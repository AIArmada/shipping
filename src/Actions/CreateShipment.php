<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Events\ShipmentCreated;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\Draft;
use AIArmada\Shipping\Support\ShippingOwnerScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateShipment
{
    use AsAction;

    public function handle(ShipmentData $data, ?string $ownerId = null, ?string $ownerType = null): Shipment
    {
        return DB::transaction(function () use ($data, $ownerId, $ownerType): Shipment {
            $resolvedOwner = ShippingOwnerScope::resolveOwner();

            if (ShippingOwnerScope::isEnabled() && $resolvedOwner === null) {
                throw new AuthorizationException('Owner context is required when shipping owner scoping is enabled.');
            }

            if (ShippingOwnerScope::isEnabled() && ($ownerId !== null || $ownerType !== null)) {
                if ($resolvedOwner === null) {
                    throw new AuthorizationException('Cannot assign an owner without an owner context.');
                }

                if ($ownerId !== (string) $resolvedOwner->getKey() || $ownerType !== $resolvedOwner->getMorphClass()) {
                    throw new AuthorizationException('Cannot assign an owner outside the current owner context.');
                }
            }

            $shipment = Shipment::create([
                'reference' => $data->reference,
                'carrier_code' => $data->carrierCode,
                'service_code' => $data->serviceCode,
                'status' => Draft::class,
                'origin_address' => $data->origin->toArray(),
                'destination_address' => $data->destination->toArray(),
                'total_weight' => $data->getTotalWeight(),
                'declared_value' => $data->declaredValue ?? 0,
                'currency' => $data->currency ?? 'MYR',
                'cod_amount' => $data->codAmount,
                'metadata' => $data->metadata,
                'owner_type' => $resolvedOwner?->getMorphClass() ?? $ownerType,
                'owner_id' => $resolvedOwner?->getKey() ?? $ownerId,
            ]);

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
                ]);
            }

            $totalWeight = $shipment->items()->sum(DB::raw('weight * quantity'));
            $shipment->update(['total_weight' => $totalWeight]);

            event(new ShipmentCreated($shipment));

            return $shipment->refresh();
        });
    }
}
