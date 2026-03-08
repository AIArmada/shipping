<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Result of creating a shipment with a carrier.
 */
class ShipmentResultData extends Data
{
    /**
     * @param  array<string>  $errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $carrierReference = null,
        public readonly ?string $labelUrl = null,
        public readonly ?string $error = null,
        public readonly array $errors = [],
        public readonly bool $requiresManualFulfillment = false,
        public readonly ?array $rawResponse = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }
}
