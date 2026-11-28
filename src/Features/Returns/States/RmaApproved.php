<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\States;

final class RmaApproved extends ReturnAuthorizationStatus
{
    public static string $name = 'approved';

    public function label(): string
    {
        return 'Approved';
    }

    public function color(): string
    {
        return 'success';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function isApproved(): bool
    {
        return true;
    }
}
