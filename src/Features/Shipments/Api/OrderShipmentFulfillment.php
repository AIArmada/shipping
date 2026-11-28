<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface OrderShipmentFulfillment
{
    public function hasActivePackageShipment(string $orderId, ?string $ownerType, string | int | null $ownerId): bool;

    public function allItemsShipped(string $orderId, ?string $ownerType, string | int | null $ownerId, ?string $shipmentId): bool;
}
