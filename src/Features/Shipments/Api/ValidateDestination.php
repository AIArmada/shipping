<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

use AIArmada\Shipping\Data\AddressData;

interface ValidateDestination
{
    /** @return array<string, string> */
    public function validate(AddressData $destination): array;
}
