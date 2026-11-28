<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Actions;

use AIArmada\Inventory\Cart\CompositeComponents;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Inventory\Services\InventoryService;
use AIArmada\Inventory\Support\InventoryOwnerScope;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Actions\RegisterOrderRefund;
use AIArmada\Orders\Contracts\PackageReturnVerifier;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorizationItem;
use AIArmada\Shipping\Features\Returns\States\RmaCancelled;
use AIArmada\Shipping\Features\Returns\States\RmaCompleted;
use AIArmada\Shipping\Features\Returns\States\RmaDraft;
use AIArmada\Shipping\Features\Returns\States\RmaExpired;
use AIArmada\Shipping\Features\Returns\States\RmaPending;
use AIArmada\Shipping\Features\Returns\States\RmaReceived;
use AIArmada\Shipping\Features\Returns\States\RmaRejected;
use AIArmada\Shipping\Features\Shipments\Api\ReadReturnShipment;
use AIArmada\Shipping\Features\Shipments\Api\ReturnShipment;
use AIArmada\Shipping\Features\Shipments\Api\ReturnShipmentLine;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PackageReturns implements PackageReturnVerifier
{
    use AssertsOrderOwnerBoundary;

    public function __construct(private readonly ReadReturnShipment $shipments) {}

    /** @return array<string, string> */
    public function shippedUnits(string $shipmentId): array
    {
        $shipment = $this->shipments->find($shipmentId);
        $order = $this->orderFor($shipment);
        $items = $order->items()->get()->keyBy('id');

        return collect($this->shipments->packageLines($shipmentId))
            ->mapWithKeys(fn (ReturnShipmentLine $line): array => [
                $line->orderItemId . ':' . $line->ordinal => $items->get($line->orderItemId)?->name . ' / unit ' . $line->ordinal,
            ])->all();
    }

    public function request(string $shipmentId, string $orderItemId, int $ordinal, string $reason): ReturnAuthorization
    {
        return DB::transaction(function () use ($shipmentId, $orderItemId, $ordinal, $reason): ReturnAuthorization {
            $shipment = $this->shipments->find($shipmentId);
            $order = $this->orderFor($shipment)->newQuery()->whereKey($shipment->orderId)->lockForUpdate()->firstOrFail();
            $this->assertOwnerBoundaryForMutation($order, __METHOD__);
            $unit = new PackageUnit($order->items()->findOrFail($orderItemId), $ordinal);
            $shipmentItems = $this->shipmentItems($shipment->id, $unit);

            $existing = $this->activeReturns($order, $unit)->first();
            if ($existing instanceof ReturnAuthorization) {
                return $existing;
            }

            $rma = new ReturnAuthorization([
                'original_shipment_id' => $shipment->id,
                'order_reference' => $order->order_number,
                'type' => 'refund',
                'reason' => $reason,
            ]);
            $rma->forceFill([
                'owner_type' => $order->owner_type,
                'owner_id' => $order->owner_id,
                'order_item_id' => $orderItemId,
                'package_unit_ordinal' => $ordinal,
            ])->save();

            foreach ($shipmentItems as $item) {
                $rma->items()->create([
                    'original_item_type' => $item->morphType,
                    'original_item_id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'quantity_requested' => $item->quantity,
                ]);
            }

            $rma->status->transitionTo(RmaPending::class);

            return $rma;
        });
    }

    /** @param array<string, array{quantity: int, condition: string, restock_quantity?: int}> $inspection Keyed by return item ID. */
    public function receive(ReturnAuthorization $rma, array $inspection, ?string $locationId = null): ReturnAuthorization
    {
        return DB::transaction(function () use ($rma, $inspection, $locationId): ReturnAuthorization {
            [$rma, $order, $unit] = $this->lockReturn($rma);
            $this->assertOwnerBoundaryForMutation($order, __METHOD__);

            if ($rma->isReceived() || $rma->isCompleted()) {
                return $rma;
            }

            if (! $rma->isApproved()) {
                throw new DomainException('Approve the package return before receiving it.');
            }

            if ($this->activeReturns($order, $unit)->whereKeyNot($rma->id)->exists()) {
                throw new DomainException('This package unit already has a return authorization.');
            }

            $items = $rma->items()->get();
            if (count($inspection) !== $items->count()) {
                throw new DomainException('Inspect every component of the package unit.');
            }

            foreach ($items as $item) {
                $result = $inspection[$item->id] ?? [];
                $quantity = $result['quantity'] ?? null;
                $condition = is_string($result['condition'] ?? null) ? $result['condition'] : '';
                if ($quantity !== $item->quantity_approved || ! in_array($condition, ReturnAuthorizationItem::CONDITIONS, true)) {
                    throw new DomainException('Every approved component must be received and inspected.');
                }

                $restockQuantity = $result['restock_quantity'] ?? ($condition === 'unopened' ? $quantity : 0);
                if (! is_int($restockQuantity) || $restockQuantity < 0 || $restockQuantity > $quantity
                    || ($restockQuantity > 0 && in_array($condition, ['damaged', 'defective'], true))
                    || ($condition === 'mixed' && ($restockQuantity === 0 || $restockQuantity === $quantity))) {
                    throw new DomainException('The sellable quantity must match the component inspection.');
                }

                $item->update([
                    'quantity_received' => $quantity,
                    'condition' => $condition,
                    'metadata' => array_replace($item->metadata ?? [], ['restock_quantity' => $restockQuantity]),
                ]);
            }

            $this->assertContents($rma, $unit, approved: true, inspected: true);

            foreach ($this->shipmentItems((string) $rma->original_shipment_id, $unit) as $index => $shippedItem) {
                $item = $items->firstWhere('original_item_id', $shippedItem->id);
                $component = $unit->components[$index];
                $restockQuantity = $item?->metadata['restock_quantity'] ?? 0;
                if ($restockQuantity === 0 || ! $component['tracks_inventory']) {
                    continue;
                }

                if (! class_exists(InventoryService::class)) {
                    throw new DomainException('Inventory must be available to restock a tracked component.');
                }

                $model = CompositeComponents::resolveInventoryModel($component);
                if ($model === null || $model->getMorphClass() !== $component['inventoryable_type']) {
                    throw new DomainException('The original component inventory identity is unavailable.');
                }

                $inventory = app(InventoryService::class);
                if ($locationId === null && InventoryOwnerScope::isEnabled()) {
                    throw new DomainException('Select the receiving inventory location.');
                }

                if ($locationId !== null && ! InventoryOwnerScope::applyToLocationQuery(
                    InventoryLocation::query(),
                )->whereKey($locationId)->exists()) {
                    throw new DomainException('The receiving inventory location is unavailable.');
                }

                if ($locationId === null) {
                    $inventory->receiveAtDefault($model, $restockQuantity, $rma->rma_number);
                } else {
                    $inventory->receive($model, $locationId, $restockQuantity, $rma->rma_number);
                }
            }

            $rma->received_at = CarbonImmutable::now();
            $rma->status->transitionTo(RmaReceived::class);

            return $rma;
        });
    }

    public function receivedUnit(Order $order, string $authorizationId): PackageUnit
    {
        $rma = ReturnAuthorization::query()->findOrFail($authorizationId);
        [$returnOrder, $unit] = $this->resolve($rma);
        if (! $returnOrder->is($order) || $rma->received_at === null || (! $rma->isReceived() && ! $rma->isCompleted())) {
            throw new DomainException('A received package return on this order is required for the refund.');
        }

        $this->assertContents($rma, $unit, approved: true, inspected: true);

        return $unit;
    }

    public function complete(ReturnAuthorization $rma, ?string $transactionId = null): ReturnAuthorization
    {
        return DB::transaction(function () use ($rma, $transactionId): ReturnAuthorization {
            [$rma, $order] = $this->lockReturn($rma);
            $this->assertOwnerBoundaryForMutation($order, __METHOD__);
            $unit = $this->receivedUnit($order, $rma->id);

            if ($rma->isCompleted()) {
                return $rma;
            }

            $remaining = $unit->refundAmount() - $this->settledRefundAmount($order, $rma);
            if ($transactionId !== null && $remaining > 0) {
                app(RegisterOrderRefund::class)->execute($order, $remaining, $transactionId, $rma->reason, [
                    'return_authorization_id' => $rma->id,
                ]);
            }

            $rma->status->transitionTo(RmaCompleted::class);

            return $rma;
        });
    }

    public function approveItems(ReturnAuthorization $rma): void
    {
        [$order, $unit] = $this->resolve($rma);
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);
        $this->assertContents($rma, $unit);

        foreach ($rma->items()->get() as $item) {
            $item->update(['quantity_approved' => $item->quantity_requested]);
        }
    }

    public function assertStatus(ReturnAuthorization $rma): void
    {
        [$order, $unit] = $this->resolve($rma);

        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $rma->exists && ! $rma->status instanceof RmaDraft) {
            throw new DomainException('A package return must start as a draft.');
        }

        if ($rma->isPending() || $rma->isApproved()) {
            $this->assertContents($rma, $unit, approved: $rma->isApproved());
        }

        if ($rma->isReceived() || $rma->isCompleted()) {
            $statusBefore = $rma->getOriginal('status');
            if (($rma->isReceived() && ! $statusBefore?->isApproved())
                || ($rma->isCompleted() && ! $statusBefore instanceof RmaReceived)) {
                throw new DomainException('A package return must be approved, received, and then completed.');
            }

            if ($rma->received_at === null) {
                throw new DomainException('Receive the package unit through the package return workflow.');
            }

            $this->assertContents($rma, $unit, approved: true, inspected: true);
        }

        if ($rma->isCompleted()) {
            $refunds = $order->refunds()->where('metadata->return_authorization_id', $rma->id);
            if ($refunds->where('status', RefundStatus::Pending)->exists()
                || $this->settledRefundAmount($order, $rma) !== $unit->refundAmount()) {
                throw new DomainException('Settle the package unit refund before completing the return.');
            }

            $rma->completed_at = CarbonImmutable::now();
        }
    }

    private function settledRefundAmount(Order $order, ReturnAuthorization $rma): int
    {
        return (int) $order->refunds()->where('metadata->return_authorization_id', $rma->id)
            ->where('status', RefundStatus::Completed)->sum('amount');
    }

    /** @return Builder<ReturnAuthorization> */
    private function activeReturns(Order $order, PackageUnit $unit): Builder
    {
        return ReturnAuthorization::query()
            ->where('order_item_id', $unit->item->id)
            ->where('package_unit_ordinal', $unit->ordinal)
            ->where('owner_type', $order->owner_type)
            ->where('owner_id', $order->owner_id)
            ->whereNotIn('status', [RmaCancelled::$name, RmaExpired::$name, RmaRejected::$name]);
    }

    /** @return array{ReturnAuthorization, Order, PackageUnit} */
    private function lockReturn(ReturnAuthorization $rma): array
    {
        $rma = ReturnAuthorization::query()->findOrFail($rma->id);
        [$order, $unit] = $this->resolve($rma);
        $order->newQuery()->lockForUpdate()->findOrFail($order->id);
        $rma = ReturnAuthorization::query()->lockForUpdate()->findOrFail($rma->id);

        return [$rma, $order, $unit];
    }

    /** @return array{Order, PackageUnit} */
    private function resolve(ReturnAuthorization $rma): array
    {
        $shipment = $this->shipments->find((string) $rma->original_shipment_id);
        $order = $this->orderFor($shipment);
        if ($rma->owner_id !== $order->owner_id || $rma->owner_type !== $order->owner_type
            || $rma->order_item_id === null || $rma->package_unit_ordinal === null || $rma->type !== 'refund') {
            throw new DomainException('The package return must identify one unit on the original shipment.');
        }

        return [$order, new PackageUnit($order->items()->findOrFail($rma->order_item_id), $rma->package_unit_ordinal)];
    }

    private function orderFor(ReturnShipment $shipment): Order
    {
        if ($shipment->orderId === null || $shipment->orderType !== (new Order)->getMorphClass()) {
            throw new DomainException('The original shipment must belong to the order.');
        }

        $order = Order::query()->findOrFail($shipment->orderId);

        if ($shipment->ownerId !== $order->owner_id || $shipment->ownerType !== $order->owner_type) {
            throw new DomainException('The original shipment must belong to the order.');
        }

        return $order;
    }

    /** @return Collection<int, ReturnShipmentLine> */
    private function shipmentItems(string $shipmentId, PackageUnit $unit): Collection
    {
        return collect($this->shipments->unitLines($shipmentId, $unit->item->id, $unit->ordinal));
    }

    private function assertContents(ReturnAuthorization $rma, PackageUnit $unit, bool $approved = false, bool $inspected = false): void
    {
        $shipmentItems = $this->shipmentItems((string) $rma->original_shipment_id, $unit)->keyBy('id');
        $items = $rma->items()->get();
        if ($items->count() !== $shipmentItems->count() || $items->pluck('original_item_id')->unique()->count() !== $items->count()) {
            throw new DomainException('A return must cover one complete package unit.');
        }

        foreach ($items as $item) {
            $shipped = $shipmentItems->get($item->original_item_id);
            if ($shipped === null || $item->original_item_type !== $shipped->morphType
                || $item->quantity_requested !== $shipped->quantity
                || ($approved && $item->quantity_approved !== $shipped->quantity)
                || ($inspected && ($item->quantity_received !== $shipped->quantity
                    || ! in_array($item->condition, ReturnAuthorizationItem::CONDITIONS, true)))) {
                throw new DomainException('A return must cover one complete package unit with every component inspected before refund.');
            }
        }
    }
}
