<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Queries;

use AIArmada\Orders\Models\Order;
use AIArmada\Shipping\Features\Shipments\Api\OrderShipmentFulfillment;

final readonly class ReadOrderShipmentFulfillment implements OrderShipmentFulfillment
{
    public function hasActivePackageShipment(string $orderId, ?string $ownerType, string | int | null $ownerId): bool
    {
        $order = $this->order($orderId, $ownerType, $ownerId);

        return $order instanceof Order && OrderShipmentUnits::hasActivePackageShipment($order);
    }

    public function allItemsShipped(string $orderId, ?string $ownerType, string | int | null $ownerId, ?string $shipmentId): bool
    {
        $order = $this->order($orderId, $ownerType, $ownerId);

        return $order instanceof Order && (new OrderShipmentUnits)->allItemsShipped($order, $shipmentId);
    }

    private function order(string $orderId, ?string $ownerType, string | int | null $ownerId): ?Order
    {
        $order = Order::query()->withoutOwnerScope()->find($orderId);

        if (! $order instanceof Order
            || $order->owner_type !== $ownerType
            || ($order->owner_id === null ? null : (string) $order->owner_id) !== ($ownerId === null ? null : (string) $ownerId)) {
            return null;
        }

        return $order;
    }
}
