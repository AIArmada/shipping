<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\ZoneCandidateData;

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
     * @param  list<ZoneCandidateData>  $candidates
     * @return list<string> Ordered candidate IDs
     */
    public function resolve(AddressData $address, array $candidates): array;
}
