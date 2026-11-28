<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;

/**
 * Balances speed and cost using weighted scoring.
 */
class BalancedRateStrategy implements RateSelectionStrategyInterface
{
    public function __construct(
        protected readonly float $speedWeight = 0.5,
        protected readonly float $costWeight = 0.5
    ) {}

    public function select(array $rates, array $options = []): ?RateQuoteData
    {
        if ($rates === []) {
            return null;
        }

        $speedWeight = $options['speed_weight'] ?? $this->speedWeight;
        $costWeight = $options['cost_weight'] ?? $this->costWeight;

        $maxRate = 1;
        $maxDays = 1;

        foreach ($rates as $rate) {
            $maxRate = max($maxRate, $rate->rate);
            $maxDays = max($maxDays, $rate->estimatedDays);
        }

        $best = null;
        $bestScore = -INF;

        foreach ($rates as $rate) {
            $score = ($speedWeight * (1 - $rate->estimatedDays / $maxDays))
                + ($costWeight * (1 - $rate->rate / $maxRate));

            if ($score > $bestScore) {
                $best = $rate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function getStrategyName(): string
    {
        return 'balanced';
    }
}
