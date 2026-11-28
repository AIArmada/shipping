<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

final readonly class ReturnShipmentLine
{
    public function __construct(
        public string $id,
        public string $morphType,
        public ?string $orderItemId,
        public ?int $ordinal,
        public string $name,
        public ?string $sku,
        public int $quantity,
    ) {}
}
