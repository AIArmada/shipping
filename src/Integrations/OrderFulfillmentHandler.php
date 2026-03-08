<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Integrations;

use AIArmada\Orders\Contracts\FulfillmentHandler;
use AIArmada\Orders\Models\Order;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentItemData;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Services\ShipmentService;
use AIArmada\Shipping\ShippingManager;
use DateTimeInterface;
use Throwable;

/**
 * Bridges the orders package with shipping functionality.
 *
 * This handler implements the FulfillmentHandler contract from the orders
 * package, allowing orders to create shipments and track deliveries through
 * the shipping package infrastructure.
 *
 * When the inventory package is installed, this handler will automatically
 * use FulfillmentLocationService to determine the best warehouse to ship from.
 */
final class OrderFulfillmentHandler implements FulfillmentHandler
{
    public function __construct(
        private readonly ShippingManager $shippingManager,
        private readonly ShipmentService $shipmentService,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function createShipment(Order $order, array $shipmentData): array
    {
        try {
            $shippingAddress = $order->shippingAddress;

            if ($shippingAddress === null) {
                return [
                    'success' => false,
                    'shipment_id' => null,
                    'tracking_number' => null,
                    'error' => 'Order has no shipping address',
                ];
            }

            // Get origin address - try inventory-aware location first
            $originAddress = $this->getOriginAddressForOrder($order, $shipmentData);
            $destinationAddress = AddressData::from([
                'name' => mb_trim($shippingAddress->first_name . ' ' . $shippingAddress->last_name),
                'company' => $shippingAddress->company,
                'line1' => $shippingAddress->line1 ?? '',
                'line2' => $shippingAddress->line2,
                'city' => $shippingAddress->city,
                'state' => $shippingAddress->state,
                'postcode' => $shippingAddress->postcode ?? '',
                'country' => $shippingAddress->country ?? 'MY',
                'phone' => $shippingAddress->phone ?? '',
                'email' => $shippingAddress->email,
            ]);

            $items = $order->items->map(fn ($item) => ShipmentItemData::from([
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'weight' => $item->metadata['weight'] ?? 100,
                'declaredValue' => $item->total,
            ]))->toArray();

            $data = ShipmentData::from([
                'reference' => $order->order_number,
                'carrierCode' => $shipmentData['carrier'] ?? config('shipping.default', 'manual'),
                'serviceCode' => $shipmentData['service'] ?? 'standard',
                'origin' => $originAddress,
                'destination' => $destinationAddress,
                'items' => $items,
                'declaredValue' => $order->grand_total,
                'currency' => $order->currency,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
            ]);

            $shipment = $this->shipmentService->create(
                $data,
                $order->owner_id,
                $order->owner_type,
            );

            $this->shipmentService->markPending($shipment);
            $shipment = $this->shipmentService->ship($shipment);

            return [
                'success' => true,
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'error' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'shipment_id' => null,
                'tracking_number' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRates(Order $order): array
    {
        $shippingAddress = $order->shippingAddress;

        if ($shippingAddress === null) {
            return [];
        }

        $destination = AddressData::from([
            'name' => mb_trim($shippingAddress->first_name . ' ' . $shippingAddress->last_name),
            'line1' => $shippingAddress->line1 ?? '',
            'city' => $shippingAddress->city,
            'state' => $shippingAddress->state,
            'postcode' => $shippingAddress->postcode ?? '',
            'country' => $shippingAddress->country ?? 'MY',
            'phone' => $shippingAddress->phone ?? '',
        ]);

        $packages = [
            PackageData::from([
                'weight' => $this->calculateTotalWeight($order),
                'declaredValue' => $order->grand_total,
            ]),
        ];

        $rates = [];
        $drivers = $this->shippingManager->getDriversForDestination($destination);

        foreach ($drivers as $driver) {
            try {
                $driverRates = $driver->getRates(
                    $this->getOriginAddress(),
                    $destination,
                    $packages,
                );

                foreach ($driverRates as $rate) {
                    $rates[] = [
                        'carrier' => $rate->carrier,
                        'service' => $rate->service,
                        'rate' => $rate->rate,
                        'currency' => $rate->currency,
                    ];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $rates;
    }

    /**
     * {@inheritDoc}
     */
    public function getTracking(string $trackingNumber): array
    {
        try {
            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();

            if ($shipment === null) {
                return [
                    'status' => 'unknown',
                    'events' => [],
                ];
            }

            $driver = $this->shippingManager->driver($shipment->carrier_code);
            $trackingData = $driver->track($trackingNumber);

            return [
                'status' => $trackingData->status->value,
                'events' => $trackingData->events->map(fn ($event) => [
                    'date' => $event->timestamp->format(DateTimeInterface::ATOM),
                    'description' => $event->description,
                    'location' => $event->location,
                ])->toArray(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'unknown',
                'events' => [],
            ];
        }
    }

    /**
     * Get origin address from config.
     */
    private function getOriginAddress(): AddressData
    {
        $origin = (array) config('shipping.defaults.origin', []);

        return AddressData::from([
            'name' => $origin['name'] ?? config('app.name'),
            'company' => $origin['company'] ?? null,
            'line1' => $origin['line1'] ?? '',
            'line2' => $origin['line2'] ?? null,
            'city' => $origin['city'] ?? '',
            'state' => $origin['state'] ?? '',
            'postcode' => $origin['postcode'] ?? $origin['postCode'] ?? '',
            'country' => $origin['country'] ?? $origin['country_code'] ?? $origin['countryCode'] ?? 'MY',
            'phone' => $origin['phone'] ?? '',
            'email' => $origin['email'] ?? null,
        ]);
    }

    /**
     * Get the best origin address for an order using inventory awareness when available.
     *
     * When the inventory package is installed, this method queries the
     * FulfillmentLocationService to find the optimal warehouse that can
     * fulfill the order items. Falls back to static config if inventory
     * package is not available or if no suitable location is found.
     *
     * @param  array<string, mixed>  $shipmentData  Optional shipment data including forced location_id
     */
    private function getOriginAddressForOrder(Order $order, array $shipmentData = []): AddressData
    {
        // If a specific location_id is provided, try to use it
        $forcedLocationId = $shipmentData['location_id'] ?? null;

        // Try inventory-aware fulfillment location
        if (class_exists(\AIArmada\Inventory\Integrations\FulfillmentLocationService::class)) {
            try {
                /** @var \AIArmada\Inventory\Integrations\FulfillmentLocationService|null $fulfillmentService */
                $fulfillmentService = app(\AIArmada\Inventory\Integrations\FulfillmentLocationService::class);

                if ($fulfillmentService !== null) {
                    // Build items array from order
                    $items = $order->items
                        ->filter(fn ($item) => $item->metadata['requires_shipping'] ?? true)
                        ->map(fn ($item) => [
                            'variant_id' => $item->purchasable_id,
                            'quantity' => $item->quantity,
                        ])
                        ->values()
                        ->toArray();

                    if ($items !== []) {
                        // If forced location, check it can fulfill
                        if ($forcedLocationId !== null) {
                            $location = $this->getLocationById($forcedLocationId);
                            if ($location !== null && $this->locationCanFulfill($fulfillmentService, $location, $items)) {
                                return $this->locationToAddress($location);
                            }
                        }

                        // Find best fulfillment location using the Order
                        $location = $fulfillmentService->getBestFulfillmentLocation($order);

                        if ($location !== null) {
                            return $this->locationToAddress($location);
                        }
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Fall back to static config
        return $this->getOriginAddress();
    }

    /**
     * Get a location model by ID.
     *
     * @return object|null Location model or null
     */
    private function getLocationById(string $locationId): ?object
    {
        if (! class_exists(\AIArmada\Inventory\Models\InventoryLocation::class)) {
            return null;
        }

        try {
            return \AIArmada\Inventory\Models\InventoryLocation::find($locationId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check if a location can fulfill all items.
     *
     * @param  array<int, array{variant_id: string, quantity: int}>  $items
     */
    private function locationCanFulfill(object $fulfillmentService, object $location, array $items): bool
    {
        try {
            $availability = $fulfillmentService->getAvailabilitySummary($items);

            foreach ($availability as $item) {
                $locationStock = collect($item['locations'] ?? [])
                    ->firstWhere('location_id', $location->id);

                if ($locationStock === null || ($locationStock['available'] ?? 0) < $item['quantity_needed']) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Convert a Location model to AddressData.
     *
     * Note: InventoryLocation stores structured address fields.
     * If the address is missing, we fall back to the static origin config.
     */
    private function locationToAddress(object $location): AddressData
    {
        $origin = (array) config('shipping.defaults.origin', []);

        $line1 = $location->line1 ?? null;

        if ($line1 === null || $line1 === '') {
            $line1 = $origin['line1'] ?? '';
        }

        return AddressData::from([
            'name' => $location->name ?? config('app.name'),
            'company' => $origin['company'] ?? null,
            'line1' => $line1,
            'line2' => $location->line2 ?? $origin['line2'] ?? null,
            'city' => $location->city ?? $origin['city'] ?? '',
            'state' => $location->state ?? $origin['state'] ?? '',
            'postcode' => $location->postcode ?? $origin['postcode'] ?? $origin['postCode'] ?? '',
            'country' => $location->country ?? $origin['country'] ?? $origin['country_code'] ?? 'MY',
            'phone' => $origin['phone'] ?? '',
            'email' => $origin['email'] ?? null,
        ]);
    }

    /**
     * Calculate total weight from order items.
     */
    private function calculateTotalWeight(Order $order): int
    {
        return (int) $order->items->sum(fn ($item) => ($item->metadata['weight'] ?? 100) * $item->quantity);
    }
}
