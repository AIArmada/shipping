<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class AwaitingPickup extends ShipmentStatus
{
    public static string $name = 'awaiting_pickup';

    public function label(): string
    {
        return 'Awaiting Pickup';
    }

    public function color(): string
    {
        return 'blue';
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }
}
