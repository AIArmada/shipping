<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Integrations;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Inventory\Integrations\FulfillmentLocationService;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Inventory\Support\InventoryOwnerScope;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Contracts\FulfillmentHandler;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentItemData;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentItem;
use AIArmada\Shipping\Services\ShipmentService;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Draft;
use AIArmada\Shipping\Support\ComponentLines;
use AIArmada\Shipping\Support\DestinationAddressValidator;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
    use AssertsOrderOwnerBoundary;

    public function __construct(
        private readonly ShippingManager $shippingManager,
        private readonly ShipmentService $shipmentService,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function availableCarriers(): array
    {
        $carriers = [];

        foreach ($this->shippingManager->getAvailableDrivers() as $driverName) {
            try {
                $driver = $this->shippingManager->driver($driverName);
                $code = mb_trim($driver->getCarrierCode());
                $name = mb_trim($driver->getCarrierName());

                if ($code === '') {
                    continue;
                }

                $carriers[$code] = $name !== '' ? $name : $code;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $carriers;
    }

    /**
     * {@inheritDoc}
     */
    public function createShipment(Order $order, array $shipmentData): array
    {
        try {
            $this->assertOwnerBoundaryForMutation($order, __METHOD__);
            $shippingAddress = $order->primaryAddress('shipping');

            if ($shippingAddress === null) {
                return [
                    'success' => false,
                    'shipment_id' => null,
                    'tracking_number' => null,
                    'error' => 'Order has no shipping address',
                ];
            }

            $destinationAddress = $this->toShippingAddressData($shippingAddress, includeEmail: true);
            $violations = app(DestinationAddressValidator::class)->validate($destinationAddress);

            if ($violations !== []) {
                throw new InvalidArgumentException('Destination address is not shippable: ' . implode(' ', $violations));
            }

            $shipment = DB::transaction(function () use ($order, $shipmentData, $destinationAddress): Shipment {
                $order = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $order->load('items');

                $hasPackages = $order->items->contains(fn ($item): bool => PackageUnit::componentsOf($item) !== []);
                if ($hasPackages && ($order->isCanceled() || $order->isFinal())) {
                    throw new InvalidArgumentException('This order can no longer ship package units.');
                }

                $selection = $shipmentData['package_units'] ?? null;
                if ($selection !== null && (! $hasPackages || ! is_array($selection) || $selection === [])) {
                    throw new InvalidArgumentException('Select package units by order item and ordinal.');
                }

                $carrier = $shipmentData['carrier'] ?? config('shipping.drivers.default', 'manual');
                $service = $shipmentData['service'] ?? 'standard';
                $parcels = array_map(
                    fn (array $parcel): PackageData => PackageData::from($parcel),
                    $shipmentData['parcels'] ?? [],
                );
                $shipments = collect();
                $reference = $order->order_number;

                if ($hasPackages) {
                    if ($selection !== null) {
                        foreach ($selection as $itemId => &$ordinals) {
                            if (! is_array($ordinals) || ! array_is_list($ordinals) || $ordinals === []
                                || array_filter($ordinals, 'is_int') !== $ordinals
                                || array_unique($ordinals) !== $ordinals
                                || ! $order->items->contains(fn ($item): bool => $item->id === $itemId && PackageUnit::componentsOf($item) !== [])) {
                                throw new InvalidArgumentException('Select package units by order item and ordinal.');
                            }

                            sort($ordinals);
                        }
                        unset($ordinals);
                        ksort($selection);
                    }

                    $key = hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
                    $shipments = OrderShipmentUnits::forOrder($order)
                        ->with('items')
                        ->get();
                    $previous = $shipments->first(fn (Shipment $shipment): bool => ($shipment->metadata['fulfillment_key'] ?? null) === $key);
                    if ($previous instanceof Shipment) {
                        return $previous->status instanceof Draft ? $this->shipmentService->markPending($previous) : $previous;
                    }

                    $reference .= '-' . str()->ulid();
                }

                $assigned = $shipments->flatMap(fn (Shipment $shipment) => $shipment->items)
                    ->groupBy('order_item_id');
                $items = $this->shipmentItemsForOrder($order, $selection, $assigned);
                if ($hasPackages && $items === []) {
                    throw new InvalidArgumentException('No unassigned items are available to ship.');
                }

                $data = new ShipmentData(
                    reference: $reference,
                    carrierCode: $carrier,
                    serviceCode: $service,
                    origin: $this->getOriginAddressForOrder($order, $shipmentData),
                    destination: $destinationAddress,
                    items: $items,
                    packages: $parcels,
                    declaredValue: $hasPackages ? $this->declaredValue($order, $items) : $order->grand_total,
                    currency: $order->currency,
                    metadata: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        ...($hasPackages ? ['fulfillment_key' => $key] : []),
                    ],
                    shippableId: $order->id,
                    shippableType: $order->getMorphClass(),
                );

                $shipment = $this->shipmentService->create($data, $order->owner_id, $order->owner_type);

                return $hasPackages ? $this->shipmentService->markPending($shipment) : $shipment;
            });

            if ($shipment->shipped_at === null) {
                if ($shipment->status instanceof Draft) {
                    $shipment = $this->shipmentService->markPending($shipment);
                }

                $shipment = $this->shipmentService->ship($shipment);
            }

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
        $shippingAddress = $order->primaryAddress('shipping');

        if ($shippingAddress === null) {
            return [];
        }

        $destination = $this->toShippingAddressData($shippingAddress);

        $packages = $this->packagesForOrder($order);

        $rates = [];

        foreach ($this->shippingManager->getAvailableDrivers() as $carrierCode) {
            try {
                $driver = $this->shippingManager->driver($carrierCode);

                if (! $driver->servicesDestination($destination)) {
                    continue;
                }

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
     * @param  array<string, list<int>>|null  $selection
     * @param  Collection<string, Collection<int, ShipmentItem>>  $assigned
     * @return list<ShipmentItemData>
     */
    private function shipmentItemsForOrder(Order $order, ?array $selection, Collection $assigned): array
    {
        $items = [];

        foreach ($order->items as $orderItem) {
            $metadata = $orderItem->metadata ?? [];
            if (($metadata['requires_shipping'] ?? true) === false) {
                continue;
            }

            $alreadyAssigned = $assigned->get($orderItem->id, collect());
            $components = PackageUnit::componentsOf($orderItem);

            if ($components !== []) {
                $ordinals = $selection === null
                    ? array_diff(range(1, $orderItem->quantity), $alreadyAssigned->pluck('package_unit_ordinal')->all())
                    : ($selection[$orderItem->id] ?? []);

                foreach ($ordinals as $ordinal) {
                    $unit = new PackageUnit($orderItem, $ordinal);

                    foreach ($unit->components as $index => $component) {
                        $items[] = new ShipmentItemData(
                            name: $component['name'],
                            quantity: $component['quantity'],
                            sku: $component['sku'],
                            weight: $component['weight'],
                            declaredValue: 0,
                            shippableItemId: $component['inventoryable_id'],
                            shippableItemType: $component['inventoryable_type'],
                            orderItemId: $orderItem->id,
                            packageUnitOrdinal: $ordinal,
                            packageComponentIndex: $index,
                        );
                    }
                }

                continue;
            }

            if ($selection !== null || $alreadyAssigned->isNotEmpty()) {
                continue;
            }

            $items[] = new ShipmentItemData(
                name: $orderItem->name,
                quantity: $orderItem->quantity,
                sku: $orderItem->sku,
                weight: $metadata['weight'] ?? 100,
                declaredValue: $orderItem->total,
                shippableItemId: (string) ($orderItem->purchasable_id ?? $orderItem->getKey()),
                shippableItemType: $orderItem->purchasable_type,
                orderItemId: $orderItem->id,
            );
        }

        return $items;
    }

    /** @param list<ShipmentItemData> $items */
    private function declaredValue(Order $order, array $items): int
    {
        $value = 0;

        foreach ($items as $item) {
            if ($item->packageUnitOrdinal === null) {
                $value += $item->declaredValue ?? 0;
            } elseif ($item->packageComponentIndex === 0) {
                /** @var OrderItem $orderItem */
                $orderItem = $order->items->findOrFail($item->orderItemId);
                $value += new PackageUnit($orderItem, $item->packageUnitOrdinal)->refundAmount();
            }
        }

        return $value;
    }

    /**
     * @return array<int, PackageData>
     */
    private function packagesForOrder(Order $order): array
    {
        $packages = [];

        foreach ($order->items as $orderItem) {
            $metadata = is_array($orderItem->metadata) ? $orderItem->metadata : [];
            if (($metadata['requires_shipping'] ?? true) === false) {
                continue;
            }

            $components = ComponentLines::expand(
                ComponentLines::fromOrderItemMetadata($metadata),
                (int) $orderItem->quantity,
            );

            if ($components !== []) {
                $declaredValue = (int) $orderItem->total;
                $allocatedDeclaredValue = false;

                foreach ($components as $component) {
                    if (! $component['requires_shipping'] || $component['weight'] === null) {
                        continue;
                    }

                    $weight = (int) $component['weight'] * (int) $component['quantity'];

                    if ($weight <= 0) {
                        continue;
                    }

                    $packages[] = PackageData::from([
                        'weight' => $weight,
                        'length' => $component['dimensions']['length'] ?? null,
                        'width' => $component['dimensions']['width'] ?? null,
                        'height' => $component['dimensions']['height'] ?? null,
                        'declaredValue' => $allocatedDeclaredValue ? null : $declaredValue,
                        'quantity' => 1,
                    ]);

                    $allocatedDeclaredValue = true;
                }

                continue;
            }

            $weight = (int) ($metadata['weight'] ?? 100);

            if ($weight <= 0) {
                continue;
            }

            $dimensions = ComponentLines::dimensions([
                $metadata['dimensions'] ?? null,
            ], $metadata);

            $packages[] = PackageData::from([
                'weight' => $weight * max(1, (int) $orderItem->quantity),
                'length' => $dimensions['length'] ?? null,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
                'declaredValue' => $orderItem->total,
                'quantity' => 1,
            ]);
        }

        return $packages;
    }

    /**
     * @return array<int, array{variant_id: string, quantity: int}>
     */
    private function fulfillmentItemsForOrder(Order $order): array
    {
        $items = [];

        foreach ($order->items as $orderItem) {
            $metadata = is_array($orderItem->metadata) ? $orderItem->metadata : [];
            if (($metadata['requires_shipping'] ?? true) === false) {
                continue;
            }

            $components = ComponentLines::expand(
                ComponentLines::fromOrderItemMetadata($metadata),
                (int) $orderItem->quantity,
            );

            if ($components !== []) {
                foreach ($components as $component) {
                    if (! $component['requires_shipping']) {
                        continue;
                    }

                    $items[] = [
                        'variant_id' => (string) ($component['inventoryable_id']),
                        'quantity' => (int) $component['quantity'],
                    ];
                }

                continue;
            }

            $items[] = [
                'variant_id' => (string) $orderItem->purchasable_id,
                'quantity' => (int) $orderItem->quantity,
            ];
        }

        return $items;
    }

    private function toShippingAddressData(Address $address, bool $includeEmail = false): AddressData
    {
        $metadata = $address->metadata;
        $contact = is_array($metadata)
            && is_array($metadata[Order::ADDRESS_CONTACT_METADATA_KEY] ?? null)
            ? $metadata[Order::ADDRESS_CONTACT_METADATA_KEY]
            : [];

        $data = [
            'name' => mb_trim(implode(' ', array_filter([
                $contact['first_name'] ?? null,
                $contact['last_name'] ?? null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'company' => is_string($contact['company'] ?? null) ? $contact['company'] : null,
            'line1' => $address->line1 ?? '',
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode ?? '',
            'country' => $address->country ?? $address->country_code ?? 'MY',
            'phone' => is_string($contact['phone'] ?? null) ? $contact['phone'] : '',
        ];

        if ($includeEmail) {
            $data['email'] = is_string($contact['email'] ?? null) ? $contact['email'] : null;
        }

        return AddressData::from($data);
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
        if (class_exists(FulfillmentLocationService::class)) {
            try {
                /** @var FulfillmentLocationService|null $fulfillmentService */
                $fulfillmentService = app(FulfillmentLocationService::class);

                if ($fulfillmentService !== null) {
                    // Build actual sellable inventory requirements from the
                    // frozen composition rather than the composite parent.
                    $items = $this->fulfillmentItemsForOrder($order);

                    if ($items !== []) {
                        // If forced location, check it can fulfill
                        if ($forcedLocationId !== null) {
                            $location = $this->getLocationById((string) $forcedLocationId, $order);
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
     * Get a location model by ID, scoped to the order's owner.
     *
     * A caller-supplied location must belong to the same owner as the order;
     * otherwise shipments could originate from (and disclose) a foreign
     * warehouse. Unresolvable locations fall back to automatic selection.
     *
     * @return object|null Location model or null
     */
    private function getLocationById(string $locationId, Order $order): ?object
    {
        if (! class_exists(InventoryLocation::class)) {
            return null;
        }

        try {
            // Inventory locations carry no global owner scope; apply the
            // package scoping explicitly under the order's owner context.
            $owner = OwnerContext::fromTypeAndId($order->owner_type ?? null, $order->owner_id ?? null);

            $location = OwnerContext::withOwner(
                $owner,
                static fn (): ?InventoryLocation => InventoryOwnerScope::applyToLocationQuery(
                    InventoryLocation::query()
                )->whereKey($locationId)->first(),
            );

            return $location instanceof InventoryLocation ? $location : null;
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
                $locations = is_array($item['locations'] ?? null) ? $item['locations'] : [];

                /** @var list<array<string, mixed>> $locations */
                $locationStock = collect($locations)
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
        $total = 0;

        foreach ($order->items as $item) {
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            if (($metadata['requires_shipping'] ?? true) === false) {
                continue;
            }

            $components = ComponentLines::expand(
                ComponentLines::fromOrderItemMetadata($metadata),
                (int) $item->quantity,
            );

            if ($components !== []) {
                foreach ($components as $component) {
                    if (! $component['requires_shipping']) {
                        continue;
                    }

                    $total += (int) ($component['weight'] ?? 0) * (int) $component['quantity'];
                }

                continue;
            }

            $total += (int) ($metadata['weight'] ?? 100) * (int) $item->quantity;
        }

        return $total;
    }
}
