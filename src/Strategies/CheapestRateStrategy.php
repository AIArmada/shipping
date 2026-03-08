<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;
use Illuminate\Support\Collection;

/**
 * Selects the cheapest rate.
 */
class CheapestRateStrategy implements RateSelectionStrategyInterface
{
    public function select(Collection $rates, array $options = []): ?RateQuoteData
    {
        if ($rates->isEmpty()) {
            return null;
        }

        return $rates->sortBy('rate')->first();
    }

    public function getStrategyName(): string
    {
        return 'cheapest';
    }
}
