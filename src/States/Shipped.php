<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class Shipped extends ShipmentStatus
{
    public static string $name = 'shipped';

    public function label(): string
    {
        return 'Shipped';
    }

    public function color(): string
    {
        return 'indigo';
    }

    public function icon(): string
    {
        return 'heroicon-o-paper-airplane';
    }

    public function isInTransit(): bool
    {
        return true;
    }
}
