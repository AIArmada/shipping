<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Data for a shipment item.
 */
class ShipmentItemData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly ?string $sku = null,
        public readonly ?int $weight = null, // in grams
        public readonly ?int $declaredValue = null, // in cents
        public readonly ?string $description = null,
        public readonly ?string $hsCode = null,
        public readonly ?string $originCountry = null,
        public readonly ?string $shippableItemId = null,
        public readonly ?string $shippableItemType = null,
    ) {}
}
