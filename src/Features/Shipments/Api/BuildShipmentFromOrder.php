<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

use AIArmada\Shipping\Data\ShipmentData;

interface BuildShipmentFromOrder
{
    /** @param array<string, mixed> $orderData */
    public function build(array $orderData): ShipmentData;
}
