<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class OnHold extends ShipmentStatus
{
    public static string $name = 'on_hold';

    public function label(): string
    {
        return 'On Hold';
    }

    public function color(): string
    {
        return 'yellow';
    }

    public function icon(): string
    {
        return 'heroicon-o-pause-circle';
    }
}
