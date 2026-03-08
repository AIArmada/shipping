<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Address data transfer object.
 */
class AddressData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly string $line1,
        public readonly string $postcode,
        public readonly string $country = 'MY',
        public readonly ?string $company = null,
        public readonly ?string $email = null,
        public readonly ?string $line2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly bool $isResidential = true,
    ) {}

    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    public function getFormattedName(): string
    {
        if ($this->company) {
            return "{$this->name} ({$this->company})";
        }

        return $this->name;
    }
}
