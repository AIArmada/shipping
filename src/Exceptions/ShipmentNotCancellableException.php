<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use Exception;

class ShipmentNotCancellableException extends Exception
{
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
    ) {
        parent::__construct(
            "Shipment [{$reference}] cannot be cancelled in its current state [{$status}]."
        );
    }
}
