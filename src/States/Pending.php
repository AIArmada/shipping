<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class Pending extends ShipmentStatus
{
    public static string $name = 'pending';

    public function label(): string
    {
        return 'Pending';
    }

    public function color(): string
    {
        return 'yellow';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function isPending(): bool
    {
        return true;
    }

    public function isCancellable(): bool
    {
        return true;
    }
}
