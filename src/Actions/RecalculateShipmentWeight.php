<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentItem;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecalculateShipmentWeight
{
    use AsAction;

    public function handle(Shipment $shipment): Shipment
    {
        $totalWeight = $shipment->items()->get()->sum(
            fn (ShipmentItem $item): int => $item->weight * $item->quantity,
        );

        $shipment->update(['total_weight' => $totalWeight]);

        return $shipment->refresh();
    }
}
