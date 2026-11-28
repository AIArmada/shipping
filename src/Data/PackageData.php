<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Package data for rate calculations.
 */
final readonly class PackageData
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        return new self(...$data);
    }

    public function __construct(
        public int $weight, // in grams
        public ?int $length = null, // in cm
        public ?int $width = null,
        public ?int $height = null,
        public ?int $declaredValue = null, // in cents
        public ?string $packagingType = null,
        public int $quantity = 1,
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
