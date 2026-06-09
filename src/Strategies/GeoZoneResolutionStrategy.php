<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Models\ShippingZone;
use Illuminate\Support\Collection;

/**
 * Geo-based zone resolution strategy.
 *
 * Matches zones by comparing address fields (country, state,
 * city, postcode) against zone rules. This is the default strategy.
 */
class GeoZoneResolutionStrategy implements ZoneResolutionStrategyInterface
{
    public function key(): string
    {
        return 'geo';
    }

    public function resolve(AddressData $address, Collection $candidates): Collection
    {
        $matched = $candidates->filter(fn (ShippingZone $zone) => $zone->matchesAddress($address));

        if ($matched->isNotEmpty()) {
            return $matched;
        }

        return $candidates->filter(fn (ShippingZone $zone) => $zone->is_default);
    }
}
