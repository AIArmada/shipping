<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States\ReturnAuthorizationState;

final class RmaCompleted extends ReturnAuthorizationStatus
{
    public static string $name = 'completed';

    public function label(): string
    {
        return 'Completed';
    }

    public function color(): string
    {
        return 'success';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-badge';
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
