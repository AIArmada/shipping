<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\CommerceSupport\Support\MoneyFormatter;

/**
 * Result of free shipping evaluation.
 */
class FreeShippingResult
{
    public function __construct(
        public readonly bool $applies,
        public readonly ?string $message = null,
        public readonly ?int $remainingAmount = null,
        public readonly ?string $currency = null,
        public readonly bool $nearThreshold = false,
    ) {}

    public function getFormattedRemaining(): ?string
    {
        if ($this->remainingAmount === null) {
            return null;
        }

        return MoneyFormatter::formatMinor($this->remainingAmount, $this->currency ?? config('shipping.defaults.currency', 'MYR'));
    }
}
