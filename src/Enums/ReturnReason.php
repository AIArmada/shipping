<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Enums;

/**
 * Return reason codes.
 */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case DoesNotFit = 'does_not_fit';
    case ChangedMind = 'changed_mind';
    case BetterPrice = 'better_price';
    case NoLongerNeeded = 'no_longer_needed';
    case ArrivedTooLate = 'arrived_too_late';
    case Unauthorized = 'unauthorized';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Damaged => 'Item Damaged',
            self::Defective => 'Item Defective',
            self::WrongItem => 'Wrong Item Received',
            self::NotAsDescribed => 'Not as Described',
            self::DoesNotFit => 'Does Not Fit',
            self::ChangedMind => 'Changed Mind',
            self::BetterPrice => 'Found Better Price',
            self::NoLongerNeeded => 'No Longer Needed',
            self::ArrivedTooLate => 'Arrived Too Late',
            self::Unauthorized => 'Unauthorized Purchase',
            self::Other => 'Other',
        };
    }

    public function isSellerFault(): bool
    {
        return in_array($this, [
            self::Damaged,
            self::Defective,
            self::WrongItem,
            self::NotAsDescribed,
        ], true);
    }

    public function requiresDetails(): bool
    {
        return $this === self::Other || $this === self::NotAsDescribed;
    }
}
