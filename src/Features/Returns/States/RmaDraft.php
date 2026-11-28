<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\States;

final class RmaDraft extends ReturnAuthorizationStatus
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
}
