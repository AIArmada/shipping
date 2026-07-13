<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Enums;

enum ShipmentOperationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Pending, self::Unknown => false,
        };
    }

    public function canRetry(): bool
    {
        return match ($this) {
            self::Failed, self::Unknown => true,
            self::Pending, self::Succeeded => false,
        };
    }
}
