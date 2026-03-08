<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use AIArmada\Shipping\States\ShipmentStatus;
use Exception;

class InvalidStatusTransitionException extends Exception
{
    public function __construct(
        public readonly ShipmentStatus $from,
        public readonly ShipmentStatus $to
    ) {
        parent::__construct(
            "Cannot transition shipment from [{$from->getValue()}] to [{$to->getValue()}]."
        );
    }
}
