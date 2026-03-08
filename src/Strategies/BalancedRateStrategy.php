<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;
use Illuminate\Support\Collection;

/**
 * Balances speed and cost using weighted scoring.
 */
class BalancedRateStrategy implements RateSelectionStrategyInterface
{
    public function __construct(
        protected readonly float $speedWeight = 0.5,
        protected readonly float $costWeight = 0.5
    ) {}

    public function select(Collection $rates, array $options = []): ?RateQuoteData
    {
        if ($rates->isEmpty()) {
            return null;
        }

        $speedWeight = $options['speed_weight'] ?? $this->speedWeight;
        $costWeight = $options['cost_weight'] ?? $this->costWeight;

        $maxRate = $rates->max('rate') ?: 1;
        $maxDays = $rates->max('estimatedDays') ?: 1;

        $scored = $rates->map(function (RateQuoteData $rate) use ($maxRate, $maxDays, $speedWeight, $costWeight) {
            // Higher score = better
            $speedScore = 1 - ($rate->estimatedDays / $maxDays);
            $costScore = 1 - ($rate->rate / $maxRate);
            $totalScore = ($speedWeight * $speedScore) + ($costWeight * $costScore);

            return ['rate' => $rate, 'score' => $totalScore];
        });

        $best = $scored->sortByDesc('score')->first();

        return $best['rate'] ?? null;
    }

    public function getStrategyName(): string
    {
        return 'balanced';
    }
}
