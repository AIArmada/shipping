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
        $totalWeight = (int) $shipment->items()->sum(DB::raw('weight * quantity'));

        $shipment->update(['total_weight' => $totalWeight]);

        return $shipment->refresh();
    }
}
