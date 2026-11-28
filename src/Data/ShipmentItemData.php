<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Data for a shipment item.
 */
final readonly class ShipmentItemData
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        return new self(...$data);
    }

    public function __construct(
        public string $name,
        public int $quantity,
        public ?string $sku = null,
        public ?int $weight = null, // in grams
        public ?int $declaredValue = null, // in cents
        public ?string $description = null,
        public ?string $hsCode = null,
        public ?string $originCountry = null,
        public ?string $shippableItemId = null,
        public ?string $shippableItemType = null,
        public ?string $orderItemId = null,
        public ?int $packageUnitOrdinal = null,
        public ?int $packageComponentIndex = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
