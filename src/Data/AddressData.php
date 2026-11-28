<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Address data transfer object.
 */
final readonly class AddressData
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        return new self(...$data);
    }

    public function __construct(
        public string $name,
        public string $phone,
        public string $line1,
        public string $postcode,
        public string $country = 'MY',
        public ?string $company = null,
        public ?string $email = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public bool $isResidential = true,
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
