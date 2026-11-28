<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Persistence;

use AIArmada\Shipping\Features\Rates\Models\ShippingZone;

final readonly class DeleteZoneRates
{
    public function handle(ShippingZone $zone): void
    {
        $zone->rates()->delete();
    }
}
