<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Integrations;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentItem;
use AIArmada\Shipping\States\Cancelled;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class OrderShipmentUnits
{
    /** @return Builder<Shipment> */
    public static function forOrder(Order $order): Builder
    {
        return Shipment::query()
            ->where('shippable_type', $order->getMorphClass())
            ->where('shippable_id', $order->id)
            ->where('owner_type', $order->owner_type)
            ->where('owner_id', $order->owner_id)
            ->where('status', '!=', Cancelled::$name);
    }

    public static function hasActivePackageShipment(Order $order): bool
    {
        if (! $order->items()->get()->contains(fn ($item): bool => PackageUnit::componentsOf($item) !== [])) {
            return false;
        }

        return self::forOrder($order)->whereHas('items', fn (Builder $items) => $items->whereNotNull('package_unit_ordinal'))->exists();
    }

    public static function assertComplete(Shipment $shipment, Order $order): void
    {
        if ($shipment->owner_id !== $order->owner_id || $shipment->owner_type !== $order->owner_type) {
            throw new DomainException('The shipment must belong to the order owner.');
        }

        $items = $order->items()->get()->keyBy('id');
        $otherShipments = self::forOrder($order)->whereKeyNot($shipment->id)->select('id');

        $shipmentItems = $shipment->items()->get();
        if ($shipmentItems->isEmpty()) {
            throw new DomainException('The order shipment must contain items.');
        }

        foreach ($shipmentItems->groupBy('order_item_id') as $orderItemId => $lines) {
            $item = $items->get($orderItemId);
            if ($item === null) {
                throw new DomainException('The shipment item must belong to the order.');
            }

            if (PackageUnit::componentsOf($item) === []) {
                if ($lines->count() !== 1 || $lines->first()->package_unit_ordinal !== null || $lines->first()->quantity !== $item->quantity) {
                    throw new DomainException('The shipment must contain the full quantity of the order line.');
                }

                continue;
            }

            foreach ($lines->groupBy('package_unit_ordinal') as $ordinal => $unitLines) {
                $unit = new PackageUnit($item, (int) $ordinal);
                self::assertUnit($unit, $unitLines);

                if (ShipmentItem::query()->whereIn('shipment_id', $otherShipments)
                    ->where('order_item_id', $item->id)->where('package_unit_ordinal', $unit->ordinal)->exists()) {
                    throw new DomainException('The package unit is already assigned to another shipment.');
                }
            }
        }
    }

    /**
     * @param  Collection<int, ShipmentItem>  $lines
     * @return Collection<int, ShipmentItem>
     */
    public static function assertUnit(PackageUnit $unit, Collection $lines): Collection
    {
        $components = $lines->keyBy('package_component_index');
        if ($lines->count() !== count($unit->components) || $components->count() !== $lines->count()) {
            throw new DomainException('All components of a package unit must be in the same shipment.');
        }

        foreach ($unit->components as $index => $component) {
            $line = $components->get($index);
            if ($line === null || $line->quantity !== $component['quantity']
                || $line->shippable_item_id !== $component['inventoryable_id']
                || $line->shippable_item_type !== $component['inventoryable_type']) {
                throw new DomainException('All components of a package unit must be in the same shipment.');
            }
        }

        return $components;
    }

    public function allItemsShipped(Order $order, ?string $shipmentId): bool
    {
        $shipments = self::forOrder($order)->whereNotNull('shipped_at');
        if ($shipmentId === null || ! $shipments->clone()->whereKey($shipmentId)->exists()) {
            throw new DomainException('Use an order shipment to fulfill package units.');
        }

        $completedShipments = $shipments->get();

        foreach ($completedShipments as $shipment) {
            self::assertComplete($shipment, $order);
        }

        $shipped = ShipmentItem::query()->whereIn('shipment_id', $completedShipments->modelKeys())->get()->groupBy('order_item_id');

        foreach ($order->items()->get() as $item) {
            if (($item->metadata['requires_shipping'] ?? true) === false) {
                continue;
            }

            $lines = $shipped->get($item->id, collect());
            if (PackageUnit::componentsOf($item) === []) {
                if ($lines->sum('quantity') < $item->quantity) {
                    return false;
                }
            } elseif ($lines->pluck('package_unit_ordinal')->unique()->count() !== $item->quantity) {
                return false;
            }
        }

        return true;
    }
}
