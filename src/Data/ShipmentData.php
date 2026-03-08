<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Data for creating a shipment.
 */
class ShipmentData extends Data
{
    /**
     * @param  array<ShipmentItemData>  $items
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $carrierCode,
        public readonly string $serviceCode,
        public readonly AddressData $origin,
        public readonly AddressData $destination,
        public readonly array $items = [],
        public readonly array $packages = [],
        public readonly ?int $declaredValue = null,
        public readonly ?string $currency = null,
        public readonly ?string $instructions = null,
        public readonly bool $signatureRequired = false,
        public readonly bool $insuranceRequired = false,
        public readonly ?int $codAmount = null,
        public readonly array $metadata = [],
    ) {}

    public function getTotalWeight(): int
    {
        if (count($this->packages) > 0) {
            return array_sum(array_map(
                fn (PackageData $p) => $p->weight * $p->quantity,
                $this->packages
            ));
        }

        return array_sum(array_map(
            fn (ShipmentItemData $i) => ($i->weight ?? 0) * $i->quantity,
            $this->items
        ));
    }

    public function isCashOnDelivery(): bool
    {
        return $this->codAmount !== null && $this->codAmount > 0;
    }
}
