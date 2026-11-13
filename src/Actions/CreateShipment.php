<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Events\ShipmentCreated;
use AIArmada\Shipping\Integrations\OrderShipmentUnits;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\Draft;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateShipment
{
    use AsAction;

    public function handle(ShipmentData $data, ?string $ownerId = null, ?string $ownerType = null): Shipment
    {
        return DB::transaction(function () use ($data, $ownerId, $ownerType): Shipment {
            $resolvedOwner = OwnerContext::resolve();

            if (config('shipping.features.owner.enabled', false) && $resolvedOwner === null) {
                throw new AuthorizationException('Owner context is required when shipping owner scoping is enabled.');
            }

            if (config('shipping.features.owner.enabled', false) && ($ownerId !== null || $ownerType !== null)) {
                if ($resolvedOwner === null) {
                    throw new AuthorizationException('Cannot assign an owner without an owner context.');
                }

                if ($ownerId !== (string) $resolvedOwner->getKey() || $ownerType !== $resolvedOwner->getMorphClass()) {
                    throw new AuthorizationException('Cannot assign an owner outside the current owner context.');
                }
            }

            // The owner tuple is never mass-assignable: it is force-filled here only
            // after the mismatch guard above validated it against the context.
            $metadata = $data->metadata;
            unset($metadata['packages']);
            if ($data->packages !== []) {
                $metadata['packages'] = array_map(fn ($package): array => $package->toArray(), $data->packages);
            }

            $shipment = new Shipment([
                'reference' => $data->reference,
                'shippable_id' => $data->shippableId,
                'shippable_type' => $data->shippableType,
                'carrier_code' => $data->carrierCode,
                'service_code' => $data->serviceCode,
                'status' => Draft::class,
                'origin_address' => $data->origin->toArray(),
                'destination_address' => $data->destination->toArray(),
                'total_weight' => $data->getTotalWeight(),
                'package_count' => $data->packages === [] ? 1 : array_sum(array_column($data->packages, 'quantity')),
                'declared_value' => $data->declaredValue ?? 0,
                'currency' => $data->currency ?? 'MYR',
                'cod_amount' => $data->codAmount,
                'metadata' => $metadata,
            ]);

            $shipment->forceFill([
                'owner_type' => config('shipping.features.owner.enabled', false)
                    ? ($resolvedOwner?->getMorphClass() ?? $ownerType)
                    : $ownerType,
                'owner_id' => config('shipping.features.owner.enabled', false)
                    ? ($resolvedOwner?->getKey() ?? $ownerId)
                    : $ownerId,
            ]);

            $shipment->save();

            foreach ($data->items as $item) {
                $shipment->items()->create([
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'weight' => $item->weight ?? 0,
                    'declared_value' => $item->declaredValue ?? 0,
                    'hs_code' => $item->hsCode,
                    'origin_country' => $item->originCountry,
                    'shippable_item_id' => $item->shippableItemId,
                    'shippable_item_type' => $item->shippableItemType,
                    'order_item_id' => $item->orderItemId,
                    'package_unit_ordinal' => $item->packageUnitOrdinal,
                    'package_component_index' => $item->packageComponentIndex,
                ]);
            }

            $order = $shipment->shippable;
            $hasPackageLines = collect($data->items)->contains(fn ($item): bool => $item->packageUnitOrdinal !== null);
            if ($hasPackageLines && ! $order instanceof Order) {
                throw new DomainException('Package units require a shipment linked to their order.');
            }

            if ($order instanceof Order) {
                $order = $order->newQuery()->lockForUpdate()->findOrFail($order->id);
                if ($hasPackageLines || $order->items()->get()->contains(fn ($item): bool => PackageUnit::componentsOf($item) !== [])) {
                    OrderShipmentUnits::assertComplete($shipment, $order);
                }
            }

            // Dispatch after commit so a rolled-back transaction cannot emit a
            // ghost waybill for a shipment that was never persisted.
            DB::afterCommit(fn (): mixed => event(new ShipmentCreated($shipment)));

            return $shipment->refresh();
        });
    }
}
