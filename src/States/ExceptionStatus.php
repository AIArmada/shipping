<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class ExceptionStatus extends ShipmentStatus
{
    public static string $name = 'exception';

    public function label(): string
    {
        return 'Exception';
    }

    public function color(): string
    {
        return 'red';
    }

    public function icon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }
}
