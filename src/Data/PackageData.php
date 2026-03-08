<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Package data for rate calculations.
 */
class PackageData extends Data
{
    public function __construct(
        public readonly int $weight, // in grams
        public readonly ?int $length = null, // in cm
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $declaredValue = null, // in cents
        public readonly ?string $packagingType = null,
        public readonly int $quantity = 1,
    ) {}

    public function getWeightKg(): float
    {
        return $this->weight / 1000;
    }

    public function getVolumetricWeight(int $divisor = 5000): int
    {
        if ($this->length === null || $this->width === null || $this->height === null) {
            return $this->weight;
        }

        // Calculate volumetric weight in kg, then convert to grams
        $volumetricKg = ($this->length * $this->width * $this->height) / $divisor;
        $volumetric = (int) ($volumetricKg * 1000); // Convert to grams

        return max($this->weight, $volumetric);
    }

    public function hasValidDimensions(): bool
    {
        return $this->length !== null
            && $this->width !== null
            && $this->height !== null;
    }
}
