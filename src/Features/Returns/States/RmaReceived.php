<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\States;

final class RmaReceived extends ReturnAuthorizationStatus
{
    public static string $name = 'received';

    public function label(): string
    {
        return 'Received';
    }

    public function color(): string
    {
        return 'primary';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-down-tray';
    }
}
