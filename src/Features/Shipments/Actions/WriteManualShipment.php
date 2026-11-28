<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Shipments\Api\ManageManualShipment;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentCreated;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\States\Draft;
use AIArmada\Shipping\Features\Shipments\States\Pending;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WriteManualShipment implements ManageManualShipment
{
    private const array FIELDS = ['reference', 'carrier_code', 'service_code', 'origin_address', 'destination_address', 'package_count', 'total_weight', 'declared_value', 'shipping_cost'];

    public function create(array $data): string
    {
        return DB::transaction(function () use ($data): string {
            $shipment = new Shipment($this->fields($data));
            $shipment->setAttribute('status', Pending::class);

            if ((bool) config('shipping.features.owner.enabled', false)) {
                $owner = OwnerContext::resolve();
                OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Shipment creation requires an owner context.');
                $shipment->forceFill(['owner_id' => $owner?->getKey(), 'owner_type' => $owner?->getMorphClass()]);
            }

            $this->assertAmounts($shipment);
            $shipment->save();
            DB::afterCommit(fn (): mixed => event(new ShipmentCreated($shipment)));

            return (string) $shipment->getKey();
        });
    }

    public function update(string $id, array $data): void
    {
        Cache::lock("shipment:{$id}:ship", 30)->block(30, function () use ($id, $data): void {
            DB::transaction(function () use ($id, $data): void {
                $shipment = $this->lock($id);
                $this->assertEditable($shipment);
                $shipment->fill($this->fields($data));
                $this->assertAmounts($shipment);
                $shipment->save();
            });
        });
    }

    public function delete(string $id): bool
    {
        return Cache::lock("shipment:{$id}:ship", 30)->block(30, fn (): bool => DB::transaction(function () use ($id): bool {
            $shipment = $this->lock($id);
            $this->assertEditable($shipment);

            return (bool) $shipment->delete();
        }));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_filter(
            array_intersect_key($data, array_flip(self::FIELDS)),
            static fn (mixed $value, string $key): bool => $value !== null || $key === 'service_code',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function lock(string $id): Shipment
    {
        if ((bool) config('shipping.features.owner.enabled', false)) {
            OwnerWriteGuard::findOrFailForOwner(Shipment::class, $id);
        }

        return Shipment::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertEditable(Shipment $shipment): void
    {
        if ((! $shipment->isPending() && ! $shipment->status instanceof Draft)
            || $shipment->shipped_at !== null
            || $shipment->tracking_number !== null
            || $shipment->shippable_id !== null
            || $shipment->operations()->exists()
            || $shipment->items()->whereNotNull('package_unit_ordinal')->exists()) {
            throw new DomainException('Only unlinked shipments without carrier operations can be edited or deleted.');
        }
    }

    private function assertAmounts(Shipment $shipment): void
    {
        if ($shipment->package_count < 1 || min($shipment->total_weight, $shipment->declared_value, $shipment->shipping_cost) < 0) {
            throw new DomainException('Shipment package count must be positive and amounts must not be negative.');
        }
    }
}
