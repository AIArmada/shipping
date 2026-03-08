<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class Delivered extends ShipmentStatus
{
    public static string $name = 'delivered';

    public function label(): string
    {
        return 'Delivered';
    }

    public function color(): string
    {
        return 'green';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function isDelivered(): bool
    {
        return true;
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
