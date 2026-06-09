<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Models\ShippingZone;
use Illuminate\Support\Collection;

/**
 * Strategy for resolving shipping zones for a given address.
 *
 * Register implementations via ZoneResolutionStrategyRegistry
 * to support multiple zone resolution rules (geo-based, B2B,
 * product-class, etc.).
 */
interface ZoneResolutionStrategyInterface
{
    /**
     * Get a unique key for this strategy.
     */
    public function key(): string;

    /**
     * Find matching zones for the given address from the candidate pool.
     *
     * @param  Collection<int, ShippingZone>  $candidates
     * @return Collection<int, ShippingZone>
     */
    public function resolve(AddressData $address, Collection $candidates): Collection;
}
