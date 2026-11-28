<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface MarkShipmentPickedUp
{
    public function execute(string $shipmentId): bool;
}
