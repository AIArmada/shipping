<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\RateQuoteData;
use Illuminate\Support\Collection;

/**
 * Contract for rate selection strategies.
 *
 * Implementations determine how to select the "best" rate from
 * a collection of available rate quotes.
 */
interface RateSelectionStrategyInterface
{
    /**
     * Select the best rate from available options.
     *
     * @param  Collection<int, RateQuoteData>  $rates
     * @param  array<string, mixed>  $options
     */
    public function select(Collection $rates, array $options = []): ?RateQuoteData;

    /**
     * Get the strategy identifier.
     */
    public function getStrategyName(): string;
}
