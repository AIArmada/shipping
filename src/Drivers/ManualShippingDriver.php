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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Manual shipping driver for merchants who handle shipping outside the system.
 */
class ManualShippingDriver implements ShippingDriverInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config = []
    ) {}

    public function getCarrierCode(): string
    {
        return 'manual';
    }

    public function getCarrierName(): string
    {
        return $this->config['name'] ?? 'Manual Shipping';
    }

    public function supports(DriverCapability $capability): bool
    {
        // Manual driver doesn't support any automated capabilities
        return false;
    }

    public function getAvailableMethods(): Collection
    {
        return collect([
            new ShippingMethodData(
                code: 'standard',
                name: 'Standard Shipping',
                description: 'Manually fulfilled',
                minDays: $this->config['estimated_days'] ?? 3,
                maxDays: ($this->config['estimated_days'] ?? 3) + 2,
                trackingAvailable: false,
            ),
        ]);
    }

    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $rate = $this->config['default_rate'] ?? 0;

        // Check for free shipping threshold
        $threshold = $this->config['free_shipping_threshold'] ?? null;
        $cartTotal = $options['cart_total'] ?? 0;

        if ($threshold !== null && $cartTotal >= $threshold) {
            $rate = 0;
        }

        return collect([
            new RateQuoteData(
                carrier: 'manual',
                service: 'standard',
                rate: $rate,
                currency: $this->config['currency'] ?? 'MYR',
                estimatedDays: $this->config['estimated_days'] ?? 3,
                serviceDescription: 'Manual fulfillment',
                calculatedLocally: true,
            ),
        ]);
    }

    public function createShipment(ShipmentData $data): ShipmentResultData
    {
        // Creates a local reference without external API
        return new ShipmentResultData(
            success: true,
            trackingNumber: 'MAN-' . mb_strtoupper(uniqid()),
            requiresManualFulfillment: true,
        );
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        // Manual shipments can always be "cancelled" locally
        return true;
    }

    public function generateLabel(string $trackingNumber, array $options = []): LabelData
    {
        // Manual driver doesn't generate labels
        return new LabelData(
            format: 'none',
            url: null,
            content: null,
        );
    }

    public function track(string $trackingNumber): TrackingData
    {
        // Manual driver returns an empty tracking response
        return new TrackingData(
            trackingNumber: $trackingNumber,
            status: TrackingStatus::AwaitingPickup,
            events: collect([
                new TrackingEventData(
                    code: 'MANUAL',
                    description: 'Manual shipment - tracking not available',
                    timestamp: CarbonImmutable::now(),
                    normalizedStatus: TrackingStatus::AwaitingPickup,
                ),
            ]),
            carrier: 'manual',
        );
    }

    public function validateAddress(AddressData $address): AddressValidationResult
    {
        // Manual driver doesn't validate addresses
        return new AddressValidationResult(
            valid: true,
            warnings: ['Address validation not available for manual shipping.'],
        );
    }

    public function servicesDestination(AddressData $destination): bool
    {
        // Manual shipping is always available
        return true;
    }
}
