<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface ShipmentOperations
{
    public function ship(string $shipmentId): void;

    public function cancel(string $shipmentId): void;

    public function syncTracking(string $shipmentId): void;
}
