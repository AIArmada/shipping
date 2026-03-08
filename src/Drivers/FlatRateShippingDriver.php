<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Drivers;

use AIArmada\Shipping\Contracts\AddressValidationResult;
use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentResultData;
use AIArmada\Shipping\Data\ShippingMethodData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Enums\DriverCapability;
use AIArmada\Shipping\Enums\TrackingStatus;
use Illuminate\Support\Collection;

/**
 * Flat rate shipping driver with configurable rate tiers.
 */
class FlatRateShippingDriver implements ShippingDriverInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config = []
    ) {}

    public function getCarrierCode(): string
    {
        return 'flat_rate';
    }

    public function getCarrierName(): string
    {
        return $this->config['name'] ?? 'Flat Rate Shipping';
    }

    public function supports(DriverCapability $capability): bool
    {
        return match ($capability) {
            DriverCapability::RateQuotes => true,
            default => false,
        };
    }

    public function getAvailableMethods(): Collection
    {
        $rates = $this->config['rates'] ?? [];

        if (! is_array($rates)) {
            $rates = [];
        }

        return collect($rates)->map(fn (array $rate, string $code) => new ShippingMethodData(
            code: $code,
            name: $rate['name'] ?? ucfirst($code),
            minDays: $rate['estimated_days'] ?? 3,
            maxDays: ($rate['estimated_days'] ?? 3) + 1,
            trackingAvailable: false,
        ));
    }

    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $rates = $this->config['rates'] ?? [];

        if (! is_array($rates)) {
            $rates = [];
        }
        $currency = $this->config['currency'] ?? 'MYR';

        return collect($rates)->map(fn (array $rate, string $code) => new RateQuoteData(
            carrier: 'flat_rate',
            service: $code,
            rate: $rate['rate'] ?? 0,
            currency: $currency,
            estimatedDays: $rate['estimated_days'] ?? 3,
            serviceDescription: $rate['name'] ?? ucfirst($code),
            calculatedLocally: true,
        ));
    }

    public function createShipment(ShipmentData $data): ShipmentResultData
    {
        return new ShipmentResultData(
            success: true,
            trackingNumber: 'FLAT-' . mb_strtoupper(uniqid()),
            requiresManualFulfillment: true,
        );
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        return true;
    }

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
                    code: 'FLAT',
                    description: 'Flat rate shipment - tracking not available',
                    timestamp: now(),
                    normalizedStatus: TrackingStatus::AwaitingPickup,
                ),
            ]),
            carrier: 'flat_rate',
        );
    }

    public function validateAddress(AddressData $address): AddressValidationResult
    {
        return new AddressValidationResult(valid: true);
    }

    public function servicesDestination(AddressData $destination): bool
    {
        return true;
    }
}
