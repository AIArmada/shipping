<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class DeliveryFailed extends ShipmentStatus
{
    public static string $name = 'delivery_failed';

    public function label(): string
    {
        return 'Delivery Failed';
    }

    public function color(): string
    {
        return 'red';
    }

    public function icon(): string
    {
        return 'heroicon-o-x-circle';
    }
}
