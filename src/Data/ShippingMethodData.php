<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Shipping method information.
 */
class ShippingMethodData extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?int $minDays = null,
        public readonly ?int $maxDays = null,
        public readonly bool $trackingAvailable = true,
        public readonly bool $signatureAvailable = false,
        public readonly bool $insuranceAvailable = false,
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
}
