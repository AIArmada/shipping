<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

final readonly class ShipmentBatchResult
{
    public function __construct(
        public int $succeeded,
        public int $failed,
    ) {}
}
