<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States\ReturnAuthorizationState;

final class RmaExpired extends ReturnAuthorizationStatus
{
    public static string $name = 'expired';

    public function label(): string
    {
        return 'Expired';
    }

    public function color(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
