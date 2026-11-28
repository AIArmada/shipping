<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface PrintableLabel
{
    public function url(string $shipmentId, string | int | null $userId): ?string;
}
