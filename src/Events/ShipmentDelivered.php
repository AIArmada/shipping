<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Events;

use AIArmada\Shipping\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentDelivered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment
    ) {}
}
