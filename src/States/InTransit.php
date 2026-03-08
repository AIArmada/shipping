<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class InTransit extends ShipmentStatus
{
    public static string $name = 'in_transit';

    public function label(): string
    {
        return 'In Transit';
    }

    public function color(): string
    {
        return 'blue';
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }

    public function isInTransit(): bool
    {
        return true;
    }
}
