<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Shipments\Api\MarkShipmentPickedUp;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\States\Shipped;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MarkStoredShipmentPickedUp implements MarkShipmentPickedUp
{
    public function execute(string $shipmentId): bool
    {
        return DB::transaction(function () use ($shipmentId): bool {
            if ((bool) config('shipping.features.owner.enabled', false)) {
                OwnerWriteGuard::findOrFailForOwner(Shipment::class, $shipmentId);
            }

            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

            if (! $shipment->status->equals(Shipped::class) || ($shipment->metadata['picked_up'] ?? false)) {
                return false;
            }

            $shipment->update([
                'metadata' => array_merge($shipment->metadata ?? [], [
                    'picked_up' => true,
                    'picked_up_at' => CarbonImmutable::now()->toDateTimeString(),
                ]),
            ]);

            return true;
        });
    }
}
