<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Result of creating a shipment with a carrier.
 */
final readonly class ShipmentResultData
{
    /**
     * @param  array<string>  $errors
     */
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?string $carrierReference = null,
        public ?string $labelUrl = null,
        public ?string $error = null,
        public array $errors = [],
        public bool $requiresManualFulfillment = false,
        public ?array $rawResponse = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
