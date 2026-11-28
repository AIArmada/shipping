<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Queries;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Features\Shipments\Api\ReadReturnShipment;
use AIArmada\Shipping\Features\Shipments\Api\ReturnShipment;
use AIArmada\Shipping\Features\Shipments\Api\ReturnShipmentLine;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentItem;
use AIArmada\Shipping\Features\Shipments\States\Cancelled;
use DomainException;

final readonly class FindReturnShipment implements ReadReturnShipment
{
    public function find(string $shipmentId): ReturnShipment
    {
        $shipment = $this->shipment($shipmentId);

        return new ReturnShipment(
            $shipment->id,
            $shipment->shippable_id,
            $shipment->shippable_type,
            $shipment->owner_id === null ? null : (string) $shipment->owner_id,
            $shipment->owner_type,
        );
    }

    public function linkedShipmentId(string $type, string $id): ?string
    {
        $shipment = Shipment::query()->where('shippable_type', $type)->where('shippable_id', $id)->first();

        return $shipment?->id;
    }

    public function isPackageComponent(?string $type, ?string $id): bool
    {
        return $id !== null && $type === (new ShipmentItem)->getMorphClass()
            && ShipmentItem::query()->whereKey($id)->whereNotNull('package_unit_ordinal')
                ->whereHas('shipment')->exists();
    }

    public function packageLines(string $shipmentId): array
    {
        return $this->shipment($shipmentId)->items()->whereNotNull('package_unit_ordinal')->get()
            ->map(fn (ShipmentItem $item): ReturnShipmentLine => $this->line($item))->values()->all();
    }

    public function unitLines(string $shipmentId, string $orderItemId, int $ordinal): array
    {
        $shipment = $this->shipment($shipmentId);
        $order = $shipment->shippable;

        if (! $order instanceof Order || $shipment->owner_id !== $order->owner_id || $shipment->owner_type !== $order->owner_type
            || $shipment->shipped_at === null || $shipment->status instanceof Cancelled) {
            throw new DomainException('The complete package unit must belong to the original shipment.');
        }

        $unit = new PackageUnit($order->items()->findOrFail($orderItemId), $ordinal);
        $lines = OrderShipmentUnits::assertUnit(
            $unit,
            $shipment->items()->where('order_item_id', $orderItemId)->where('package_unit_ordinal', $ordinal)->get(),
        );

        return $lines->map(fn (ShipmentItem $item): ReturnShipmentLine => $this->line($item))->all();
    }

    private function shipment(string $id): Shipment
    {
        return (bool) config('shipping.features.owner.enabled', false)
            ? OwnerWriteGuard::findOrFailForOwner(Shipment::class, $id)
            : Shipment::query()->findOrFail($id);
    }

    private function line(ShipmentItem $item): ReturnShipmentLine
    {
        return new ReturnShipmentLine(
            $item->id,
            $item->getMorphClass(),
            $item->order_item_id,
            $item->package_unit_ordinal,
            $item->name,
            $item->sku,
            $item->quantity,
        );
    }
}
