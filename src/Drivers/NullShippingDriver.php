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
use Illuminate\Support\Str;

/**
 * Null driver for testing purposes.
 * All operations succeed with fake data.
 */
class NullShippingDriver implements ShippingDriverInterface
{
    public function getCarrierCode(): string
    {
        return 'null';
    }

    public function getCarrierName(): string
    {
        return 'Null Driver (Testing)';
    }

    public function supports(DriverCapability $capability): bool
    {
        return true; // Everything "works" in the null driver
    }

    public function getAvailableMethods(): Collection
    {
        return collect([
            new ShippingMethodData(
                code: 'standard',
                name: 'Standard Shipping',
                minDays: 3,
                maxDays: 5,
            ),
            new ShippingMethodData(
                code: 'express',
                name: 'Express Shipping',
                minDays: 1,
                maxDays: 2,
            ),
        ]);
    }

    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        return collect([
            new RateQuoteData(
                carrier: 'null',
                service: 'standard',
                rate: 0,
                currency: 'MYR',
                estimatedDays: 3,
                serviceDescription: 'Test Standard Shipping',
                calculatedLocally: true,
            ),
            new RateQuoteData(
                carrier: 'null',
                service: 'express',
                rate: 0,
                currency: 'MYR',
                estimatedDays: 1,
                serviceDescription: 'Test Express Shipping',
                calculatedLocally: true,
            ),
        ]);
    }

    public function createShipment(ShipmentData $data): ShipmentResultData
    {
        return new ShipmentResultData(
            success: true,
            trackingNumber: 'TEST-' . mb_strtoupper(Str::random(10)),
            carrierReference: 'NULL-' . mb_strtoupper(Str::random(8)),
        );
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        return true;
    }

    public function generateLabel(string $trackingNumber, array $options = []): LabelData
    {
        return new LabelData(
            format: 'pdf',
            url: null,
            content: base64_encode('FAKE_LABEL_CONTENT'),
            trackingNumber: $trackingNumber,
        );
    }

    public function track(string $trackingNumber): TrackingData
    {
        return new TrackingData(
            trackingNumber: $trackingNumber,
            status: TrackingStatus::InTransit,
            events: collect([
                new TrackingEventData(
                    code: 'TEST',
                    description: 'Test tracking event',
                    timestamp: now(),
                    normalizedStatus: TrackingStatus::InTransit,
                ),
            ]),
            carrier: 'null',
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
