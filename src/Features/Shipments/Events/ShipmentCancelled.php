<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Events;

use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly ?string $reason = null
    ) {}
}
