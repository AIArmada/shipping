<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Support;

use AIArmada\Addressing\Actions\ValidateAddressAction;
use AIArmada\Addressing\Data\AddressData as AddressingAddressData;
use AIArmada\Shipping\Data\AddressData;

/**
 * Destination shippability check against addressing validation profiles.
 *
 * Zone matching trusts raw address fields: a destination missing its
 * country-required postcode silently skips postcode zones and falls
 * through to a default zone with the wrong rate. Validating first
 * surfaces the problem at the commit point instead.
 *
 * This is a soft integration: when aiarmada/addressing is not installed
 * the destination is unchecked and validation returns no violations.
 */
final class DestinationAddressValidator
{
    /**
     * @return array<string, string> Field name to violation message; empty when shippable or unchecked.
     */
    public function validate(AddressData $destination): array
    {
        if (! class_exists(ValidateAddressAction::class)) {
            return [];
        }

        return app(ValidateAddressAction::class)->execute(AddressingAddressData::from([
            'line1' => $destination->line1,
            'line2' => $destination->line2,
            'city' => $destination->city,
            'state' => $destination->state,
            'postcode' => $destination->postcode,
            'countryCode' => $destination->country,
        ]));
    }
}
