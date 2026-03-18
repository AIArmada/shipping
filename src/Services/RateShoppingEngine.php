<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\Strategies\CheapestRateStrategy;
use AIArmada\Shipping\Strategies\FastestRateStrategy;
use AIArmada\Shipping\Strategies\PreferredCarrierStrategy;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Aggregates rates from multiple carriers and applies selection rules.
 */
class RateShoppingEngine
{
    protected RateSelectionStrategyInterface $strategy;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly ShippingManager $shippingManager,
        protected readonly array $config = []
    ) {
        $this->strategy = $this->resolveStrategy();
    }

    /**
     * Get all available rates from all carriers.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function getAllRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $cacheKey = $this->buildCacheKey($origin, $destination, $packages, $options);
        $cacheTtl = $this->config['cache_ttl'] ?? 300;

        // If options cannot be safely hashed (objects/resources), caching can return incorrect rates.
        if ($cacheKey === null) {
            return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options);
        }

        if ($cacheTtl > 0) {
            $repository = Cache::store();

            if ($repository->getStore() instanceof TaggableStore) {
                return $repository->tags($this->cacheTags())->remember($cacheKey, $cacheTtl, function () use ($origin, $destination, $packages, $options) {
                    return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options);
                });
            }

            return $repository->remember($cacheKey, $cacheTtl, function () use ($origin, $destination, $packages, $options) {
                return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options);
            });
        }

        return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options);
    }

    /**
     * Get the best rate based on selection strategy.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     */
    public function getBestRate(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): ?RateQuoteData {
        $allRates = $this->getAllRates($origin, $destination, $packages, $options);

        if ($allRates->isEmpty()) {
            return $this->getFallbackRate($destination, $packages);
        }

        return $this->strategy->select($allRates, $options);
    }

    /**
     * Get rates for specific carriers only.
     *
     * @param  array<string>  $carriers
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function getRatesFromCarriers(
        array $carriers,
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $rates = collect();

        foreach ($carriers as $carrierCode) {
            if ($this->shippingManager->hasDriver($carrierCode)) {
                $driver = $this->shippingManager->driver($carrierCode);

                if ($driver->servicesDestination($destination)) {
                    try {
                        $carrierRates = $driver->getRates($origin, $destination, $packages, $options);
                        $rates = $rates->merge($carrierRates);
                    } catch (Throwable $e) {
                        // Log error but continue with other carriers
                        report($e);
                    }
                }
            }
        }

        return $rates->sortBy('rate');
    }

    /**
     * Set the rate selection strategy.
     */
    public function setStrategy(RateSelectionStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Clear cached rates.
     */
    public function clearCache(): void
    {
        $repository = Cache::store();

        if ($repository->getStore() instanceof TaggableStore) {
            $repository->tags($this->cacheTags())->flush();
        }
    }

    /**
     * Fetch rates from all available carriers concurrently.
     *
     * Uses Laravel's Concurrency facade to fetch rates from multiple carriers
     * in parallel, dramatically improving performance when multiple carriers
     * are configured. Each carrier call is independent with no shared state.
     *
     * Performance improvement example:
     * - Sequential: 5 carriers × 500ms = 2.5 seconds
     * - Concurrent: ~500ms (slowest carrier)
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    protected function fetchRatesFromAllCarriers(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        $drivers = $this->shippingManager->getDriversForDestination($destination);

        if ($drivers->isEmpty()) {
            return collect();
        }

        // Extract carrier codes (primitives are safely serializable)
        $carrierCodes = $drivers->map(fn ($driver) => $driver->getCarrierCode())->all();

        // If options are not concurrency-safe (contain objects/resources), fall back to sequential calls.
        if (! $this->isConcurrencySafe($options)) {
            return $this->getRatesFromCarriers($carrierCodes, $origin, $destination, $packages, $options);
        }

        // Avoid capturing complex objects in concurrent closures.
        // Concurrency may fork/process-isolate and require serialization.
        $originPayload = $origin->toArray();
        $destinationPayload = $destination->toArray();
        $packagesPayload = array_map(
            fn (PackageData $package) => $package->toArray(),
            $packages
        );

        // Build concurrent tasks - one per carrier
        // We pass primitives and re-resolve the driver in each child process
        // to avoid serialization issues with complex driver objects
        $tasks = collect($carrierCodes)->mapWithKeys(function (string $carrierCode) use ($originPayload, $destinationPayload, $packagesPayload, $options) {
            return [
                $carrierCode => function () use ($carrierCode, $originPayload, $destinationPayload, $packagesPayload, $options) {
                    try {
                        // Resolve driver fresh in child process
                        $driver = app(ShippingManager::class)->driver($carrierCode);

                        $origin = AddressData::from($originPayload);
                        $destination = AddressData::from($destinationPayload);
                        $packages = array_map(
                            fn (array $package) => PackageData::from($package),
                            $packagesPayload
                        );

                        return $driver->getRates($origin, $destination, $packages, $options);
                    } catch (Throwable $e) {
                        // Log error but return empty - other carriers may succeed
                        report($e);

                        return collect();
                    }
                },
            ];
        })->all();

        // Execute all carrier calls concurrently
        $results = Concurrency::run($tasks);

        // Merge all successful results
        $rates = collect();
        foreach ($results as $carrierRates) {
            if ($carrierRates instanceof Collection) {
                $rates = $rates->merge($carrierRates);
            }
        }

        return $rates->sortBy('rate');
    }

    protected function cacheRepository(): CacheRepository
    {
        return Cache::store();
    }

    /**
     * @return array<int, string>
     */
    protected function cacheTags(): array
    {
        return ['shipping', 'shipping:rates'];
    }

    /**
     * Get fallback rate when no carrier rates available.
     *
     * @param  array<PackageData>  $packages
     */
    protected function getFallbackRate(AddressData $destination, array $packages): ?RateQuoteData
    {
        $fallbackEnabled = $this->config['fallback_to_manual'] ?? true;

        if (! $fallbackEnabled) {
            return null;
        }

        return $this->shippingManager->driver('manual')
            ->getRates(
                new AddressData(name: '', phone: '', line1: '', postcode: ''),
                $destination,
                $packages
            )
            ->first();
    }

    /**
     * Build cache key for rate lookup.
     *
     * @param  array<PackageData>  $packages
     */
    /**
     * Build a cache key for rate lookup.
     *
     * Returns null when options are not safely hashable (objects/resources),
     * because caching would risk returning incorrect rates.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     */
    protected function buildCacheKey(AddressData $origin, AddressData $destination, array $packages, array $options = []): ?string
    {
        $totalWeight = array_sum(array_map(fn (PackageData $p) => $p->weight, $packages));

        $optionsHash = $this->hashForCache($options);
        if ($optionsHash === null) {
            return null;
        }

        return 'shipping:rates:' . md5(serialize([
            'origin' => $origin->postcode,
            'destination' => $destination->postcode . $destination->country,
            'weight' => $totalWeight,
            'packages' => count($packages),
            'options' => $optionsHash,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function isConcurrencySafe(array $options): bool
    {
        foreach ($options as $value) {
            if (is_object($value) || is_resource($value)) {
                return false;
            }

            if (is_array($value) && ! $this->isConcurrencySafe($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hash options for cache key.
     *
     * Returns null if options contain non-hashable values (objects/resources).
     *
     * @param  array<string, mixed>  $options
     */
    private function hashForCache(array $options): ?string
    {
        if (! $this->isConcurrencySafe($options)) {
            return null;
        }

        $json = json_encode($options);
        if ($json === false) {
            return null;
        }

        return md5($json);
    }

    /**
     * Resolve the rate selection strategy based on config.
     *
     * Cached using once() because this method is parameterless and reads
     * only from immutable config. Safe for request-scoped caching.
     */
    protected function resolveStrategy(): RateSelectionStrategyInterface
    {
        return once(fn () => match ($this->config['strategy'] ?? 'cheapest') {
            'fastest' => new FastestRateStrategy,
            'preferred' => new PreferredCarrierStrategy($this->config['carrier_priority'] ?? []),
            default => new CheapestRateStrategy,
        });
    }
}
