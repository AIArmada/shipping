<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use AIArmada\Shipping\Models\Shipment;
use Exception;

class ShipmentAlreadyShippedException extends Exception
{
    public function __construct(
        public readonly Shipment $shipment
    ) {
        parent::__construct(
            "Shipment [{$shipment->reference}] has already been shipped or is not in a pending state."
        );
    }
}
