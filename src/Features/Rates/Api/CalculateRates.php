<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Api;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;

interface CalculateRates
{
    /**
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return list<RateQuoteData>
     */
    public function handle(AddressData $origin, AddressData $destination, array $packages, ?string $carrier = null, array $options = []): array;
}
