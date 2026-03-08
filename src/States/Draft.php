<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class Draft extends ShipmentStatus
{
    public static string $name = 'draft';

    public function label(): string
    {
        return 'Draft';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-document';
    }

    public function isCancellable(): bool
    {
        return true;
    }
}
