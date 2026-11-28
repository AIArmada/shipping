<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

final readonly class ReturnShipment
{
    public function __construct(
        public string $id,
        public ?string $orderId,
        public ?string $orderType,
        public ?string $ownerId,
        public ?string $ownerType,
    ) {}
}
