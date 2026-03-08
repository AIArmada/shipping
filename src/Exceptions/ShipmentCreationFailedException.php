<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Exceptions;

use Exception;

class ShipmentCreationFailedException extends Exception
{
    public function __construct(string $reason)
    {
        parent::__construct("Failed to create shipment with carrier: {$reason}");
    }
}
