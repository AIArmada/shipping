<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Data for creating a shipment.
 */
final readonly class ShipmentData
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $data['origin'] = $data['origin'] instanceof AddressData ? $data['origin'] : AddressData::from($data['origin']);
        $data['destination'] = $data['destination'] instanceof AddressData ? $data['destination'] : AddressData::from($data['destination']);
        $data['items'] = array_map(static fn (ShipmentItemData | array $item): ShipmentItemData => $item instanceof ShipmentItemData ? $item : ShipmentItemData::from($item), $data['items'] ?? []);
        $data['packages'] = array_map(static fn (PackageData | array $package): PackageData => $package instanceof PackageData ? $package : PackageData::from($package), $data['packages'] ?? []);

        return new self(...$data);
    }

    /**
     * @param  list<ShipmentItemData>  $items
     * @param  list<PackageData>  $packages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $reference,
        public string $carrierCode,
        public string $serviceCode,
        public AddressData $origin,
        public AddressData $destination,
        public array $items = [],
        public array $packages = [],
        public ?int $declaredValue = null,
        public ?string $currency = null,
        public ?string $instructions = null,
        public bool $signatureRequired = false,
        public bool $insuranceRequired = false,
        public ?int $codAmount = null,
        public array $metadata = [],
        public ?string $shippableId = null,
        public ?string $shippableType = null,
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_replace(get_object_vars($this), [
            'origin' => $this->origin->toArray(),
            'destination' => $this->destination->toArray(),
            'items' => array_map(static fn (ShipmentItemData $item): array => $item->toArray(), $this->items),
            'packages' => array_map(static fn (PackageData $package): array => $package->toArray(), $this->packages),
        ]);
    }
}
