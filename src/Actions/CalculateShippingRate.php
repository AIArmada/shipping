<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\ShippingManager;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Calculate shipping rate for a package.
 */
final class CalculateShippingRate
{
    use AsAction;

    public function __construct(
        private readonly ShippingManager $shippingManager,
    ) {}

    /**
     * Calculate shipping rates for packages.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function handle(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        ?string $carrier = null,
        array $options = []
    ): Collection {
        if ($carrier !== null) {
            $driver = $this->shippingManager->driver($carrier);

            return $driver->getRates($origin, $destination, $packages, $options);
        }

        // Get rates from all registered drivers (skip drivers that don't service destination)
        $rates = collect();

        foreach ($this->shippingManager->getAvailableDrivers() as $carrierName) {
            try {
                $driver = $this->shippingManager->driver($carrierName);

                if (! $driver->servicesDestination($destination)) {
                    continue;
                }

                $carrierRates = $driver->getRates($origin, $destination, $packages, $options);
                $rates = $rates->merge($carrierRates);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
        }

        return $rates->sortBy('rate')->values();
    }
}
