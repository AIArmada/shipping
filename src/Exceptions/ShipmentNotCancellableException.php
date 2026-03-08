<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use AIArmada\Shipping\Models\Shipment;
use Exception;

class ShipmentNotCancellableException extends Exception
{
    public function __construct(
        public readonly Shipment $shipment
    ) {
        parent::__construct(
            "Shipment [{$shipment->reference}] cannot be cancelled in its current state [{$shipment->status->getValue()}]."
        );
    }
}
