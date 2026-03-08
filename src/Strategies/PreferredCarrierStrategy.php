<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\RateQuoteData;
use Illuminate\Support\Collection;

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

    public function select(Collection $rates, array $options = []): ?RateQuoteData
    {
        if ($rates->isEmpty()) {
            return null;
        }

        // If no priority configured, fall back to cheapest
        if (empty($this->priority)) {
            return $rates->sortBy('rate')->first();
        }

        // Try each preferred carrier in priority order
        $sortedPriority = $this->priority;
        asort($sortedPriority);

        foreach (array_keys($sortedPriority) as $carrier) {
            $rate = $rates->firstWhere('carrier', $carrier);

            if ($rate !== null) {
                return $rate;
            }
        }

        // Fallback to cheapest if no preferred carrier available
        return $rates->sortBy('rate')->first();
    }

    public function getStrategyName(): string
    {
        return 'preferred';
    }
}
