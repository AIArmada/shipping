<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Drivers;

use AIArmada\Shipping\Contracts\AddressValidationResult;
use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentResultData;
use AIArmada\Shipping\Data\ShippingMethodData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Enums\DriverCapability;
use AIArmada\Shipping\Enums\TrackingStatus;
use AIArmada\Shipping\Models\ShippingRate;
use AIArmada\Shipping\Services\ShippingZoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Zone-based shipping driver that uses ShippingZone/ShippingRate DB models
 * managed through the Filament admin panel.
 *
 * This driver bridges the gap between admin-managed zone/rate configuration
 * and the shipping calculation pipeline.
 */
class ZoneBasedShippingDriver implements ShippingDriverInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly ShippingZoneResolver $zoneResolver,
        protected readonly array $config = []
    ) {}

    public function getCarrierCode(): string
    {
        return 'zone';
    }

    public function getCarrierName(): string
    {
        return $this->config['name'] ?? 'Zone-Based Shipping';
    }

    public function supports(DriverCapability $capability): bool
    {
        return match ($capability) {
            DriverCapability::RateQuotes => true,
            default => false,
        };
    }

    /**
     * @return Collection<int, ShippingMethodData>
     */
    public function getAvailableMethods(): Collection
    {
        return collect([
            new ShippingMethodData(
                code: 'zone_standard',
                name: 'Zone-Based Shipping',
                description: 'Shipping rates based on destination zone',
                trackingAvailable: false,
            ),
        ]);
    }

    /**
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $ownerId = $options['owner_id'] ?? null;
        $ownerType = $options['owner_type'] ?? null;
        $cartTotal = (int) ($options['cart_total'] ?? 0);
        $itemCount = (int) ($options['item_count'] ?? 0);

        $zone = $this->zoneResolver->resolve($destination, $ownerId, $ownerType);

        if ($zone === null) {
            return collect();
        }

        $currency = $this->config['currency'] ?? config('shipping.defaults.currency', 'MYR');

        return $zone->rates()
            ->active()
            ->get()
            ->filter(fn (ShippingRate $rate) => $rate->meetsConditions($packages, $cartTotal, $itemCount))
            ->map(fn (ShippingRate $rate) => new RateQuoteData(
                carrier: $rate->carrier_code ?? 'zone',
                service: $rate->method_code,
                rate: $rate->calculateRate($packages, $cartTotal),
                currency: $currency,
                estimatedDays: $rate->estimated_days_min ?? 3,
                serviceDescription: $rate->name,
                calculatedLocally: true,
                note: $rate->description,
            ))
            ->values();
    }

    public function createShipment(ShipmentData $data): ShipmentResultData
    {
        return new ShipmentResultData(
            success: true,
            trackingNumber: 'ZONE-' . mb_strtoupper(uniqid()),
            requiresManualFulfillment: true,
        );
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generateLabel(string $trackingNumber, array $options = []): LabelData
    {
        return new LabelData(
            format: 'none',
            url: null,
            content: null,
        );
    }

    public function track(string $trackingNumber): TrackingData
    {
        return new TrackingData(
            trackingNumber: $trackingNumber,
            status: TrackingStatus::AwaitingPickup,
            events: collect([
                new TrackingEventData(
                    code: 'ZONE',
                    description: 'Zone-based shipment - tracking not available',
                    timestamp: CarbonImmutable::now(),
                    normalizedStatus: TrackingStatus::AwaitingPickup,
                ),
            ]),
            carrier: 'zone',
        );
    }

    public function validateAddress(AddressData $address): AddressValidationResult
    {
        $isServiceable = $this->zoneResolver->isServiceable($address);

        return new AddressValidationResult(
            valid: $isServiceable,
            errors: $isServiceable ? [] : ['No shipping zone covers this address'],
        );
    }

    public function servicesDestination(AddressData $destination): bool
    {
        return $this->zoneResolver->isServiceable($destination);
    }
}
