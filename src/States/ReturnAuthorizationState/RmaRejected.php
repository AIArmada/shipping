<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States\ReturnAuthorizationState;

final class RmaRejected extends ReturnAuthorizationStatus
{
    public static string $name = 'rejected';

    public function label(): string
    {
        return 'Rejected';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function icon(): string
    {
        return 'heroicon-o-x-circle';
    }

    public function isRejected(): bool
    {
        return true;
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
