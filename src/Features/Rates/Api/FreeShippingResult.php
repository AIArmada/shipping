<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Api;

use AIArmada\CommerceSupport\Support\MoneyFormatter;

/**
 * Result of free shipping evaluation.
 */
final readonly class FreeShippingResult
{
    public function __construct(
        public bool $applies,
        public ?string $message = null,
        public ?int $remainingAmount = null,
        public ?string $currency = null,
        public bool $nearThreshold = false,
    ) {}

    public function getFormattedRemaining(): ?string
    {
        if ($this->remainingAmount === null) {
            return null;
        }

        return MoneyFormatter::formatMinor($this->remainingAmount, $this->currency ?? config('shipping.defaults.currency', 'MYR'));
    }
}
