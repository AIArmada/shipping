<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;

/**
 * Selects rates from preferred carriers first.
 */
class PreferredCarrierStrategy implements RateSelectionStrategyInterface
{
    /**
     * @param  array<string, int>  $priority  Carrier code => priority (lower = higher priority)
     */
    public function __construct(
        protected readonly array $priority = []
    ) {}

    public function select(array $rates, array $options = []): ?RateQuoteData
    {
        $best = null;
        $preferred = null;
        $bestPriority = PHP_INT_MAX;

        foreach ($rates as $rate) {
            if ($best === null || $rate->rate < $best->rate) {
                $best = $rate;
            }

            $priority = $this->priority[$rate->carrier] ?? null;

            if ($priority !== null && ($preferred === null || $priority < $bestPriority || ($priority === $bestPriority && $rate->rate < $preferred->rate))) {
                $preferred = $rate;
                $bestPriority = $priority;
            }
        }

        return $preferred ?? $best;
    }

    public function getStrategyName(): string
    {
        return 'preferred';
    }
}
