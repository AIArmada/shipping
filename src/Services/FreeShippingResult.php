<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

/**
 * Result of free shipping evaluation.
 */
class FreeShippingResult
{
    public function __construct(
        public readonly bool $applies,
        public readonly ?string $message = null,
        public readonly ?int $remainingAmount = null,
        public readonly bool $nearThreshold = false,
    ) {}

    public function getFormattedRemaining(): ?string
    {
        if ($this->remainingAmount === null) {
            return null;
        }

        return number_format($this->remainingAmount / 100, 2);
    }
}
