<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Events;

use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class TrackingUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, ShipmentEvent>  $newEvents
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly Collection $newEvents
    ) {}
}
