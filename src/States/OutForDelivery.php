<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class OutForDelivery extends ShipmentStatus
{
    public static string $name = 'out_for_delivery';

    public function label(): string
    {
        return 'Out for Delivery';
    }

    public function color(): string
    {
        return 'cyan';
    }

    public function icon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public function isInTransit(): bool
    {
        return true;
    }
}
