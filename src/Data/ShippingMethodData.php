<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Shipping method information.
 */
final readonly class ShippingMethodData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
        public ?int $minDays = null,
        public ?int $maxDays = null,
        public bool $trackingAvailable = true,
        public bool $signatureAvailable = false,
        public bool $insuranceAvailable = false,
    ) {}

    public function getDeliveryEstimate(): ?string
    {
        if ($this->minDays === null && $this->maxDays === null) {
            return null;
        }

        if ($this->minDays === $this->maxDays) {
            $days = $this->minDays;

            return $days === 1 ? '1 day' : "{$days} days";
        }

        return "{$this->minDays}-{$this->maxDays} days";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
