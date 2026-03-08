<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

final class ReturnToSender extends ShipmentStatus
{
    public static string $name = 'return_to_sender';

    public function label(): string
    {
        return 'Return to Sender';
    }

    public function color(): string
    {
        return 'orange';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
