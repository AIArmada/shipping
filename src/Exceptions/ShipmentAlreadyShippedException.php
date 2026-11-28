<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use Exception;

class ShipmentAlreadyShippedException extends Exception
{
    public function __construct(
        public readonly string $reference
    ) {
        parent::__construct(
            "Shipment [{$reference}] has already been shipped or is not in a pending state."
        );
    }
}
