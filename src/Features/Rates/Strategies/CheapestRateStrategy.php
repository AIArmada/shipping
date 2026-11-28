<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;

/**
 * Selects the cheapest rate.
 */
class CheapestRateStrategy implements RateSelectionStrategyInterface
{
    public function select(array $rates, array $options = []): ?RateQuoteData
    {
        $best = null;

        foreach ($rates as $rate) {
            if ($best === null || $rate->rate < $best->rate) {
                $best = $rate;
            }
        }

        return $best;
    }

    public function getStrategyName(): string
    {
        return 'cheapest';
    }
}
