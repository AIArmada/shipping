<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;
use Illuminate\Support\Collection;

/**
 * Selects the fastest rate (lowest estimated days).
 */
class FastestRateStrategy implements RateSelectionStrategyInterface
{
    public function select(Collection $rates, array $options = []): ?RateQuoteData
    {
        if ($rates->isEmpty()) {
            return null;
        }

        return $rates->sortBy('estimatedDays')->first();
    }

    public function getStrategyName(): string
    {
        return 'fastest';
    }
}
