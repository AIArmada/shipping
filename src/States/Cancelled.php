<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class Cancelled extends ShipmentStatus
{
    public static string $name = 'cancelled';

    public function label(): string
    {
        return 'Cancelled';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-x-mark';
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
