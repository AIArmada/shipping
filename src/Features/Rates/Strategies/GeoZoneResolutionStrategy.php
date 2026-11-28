<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Strategies;

use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;

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

    public function resolve(AddressData $address, array $candidates): array
    {
        $matched = [];
        $defaults = [];

        foreach ($candidates as $zone) {
            if (! $zone->active) {
                continue;
            }

            if ($zone->matchesAddress($address)) {
                $matched[] = $zone->id;
            }

            if ($zone->isDefault) {
                $defaults[] = $zone->id;
            }
        }

        return $matched === [] ? $defaults : $matched;
    }
}
