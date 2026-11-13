<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecalculateShipmentWeight
{
    use AsAction;

    public function handle(Shipment $shipment): Shipment
    {
        $parcels = $shipment->metadata['packages'] ?? [];
        $totalWeight = $parcels === []
            ? (int) $shipment->items()->sum(DB::raw('weight * quantity'))
            : array_sum(array_map(fn (array $parcel): int => $parcel['weight'] * $parcel['quantity'], $parcels));

        $shipment->update(['total_weight' => $totalWeight]);

        return $shipment->refresh();
    }
}
