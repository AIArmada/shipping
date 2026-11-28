<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Persistence;

use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentEvent;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentItem;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentLabel;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DeleteShipmentChildren
{
    public function handle(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            foreach ([ShipmentItem::class, ShipmentEvent::class, ShipmentLabel::class, ShipmentOperation::class] as $relatedClass) {
                $relatedClass::query()->where('shipment_id', $shipment->getKey())
                    ->chunkById(500, function (Collection $children) use ($relatedClass): void {
                        $relatedClass::query()->whereKey($children->modelKeys())->delete();
                    });
            }
        });
    }
}
