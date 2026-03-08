<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Events;

use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\ShipmentStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly ShipmentStatus $oldStatus,
        public readonly ShipmentStatus $newStatus
    ) {}
}
