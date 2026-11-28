<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeKey;
use AIArmada\CommerceSupport\Support\OwnerScopeOverride;
use AIArmada\Shipping\Contracts\RateSelectionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Features\Rates\Api\ShopRates;
use AIArmada\Shipping\Features\Rates\Persistence\RateCache;
use AIArmada\Shipping\Features\Rates\Strategies\BalancedRateStrategy;
use AIArmada\Shipping\Features\Rates\Strategies\CheapestRateStrategy;
use AIArmada\Shipping\Features\Rates\Strategies\FastestRateStrategy;
use AIArmada\Shipping\Features\Rates\Strategies\PreferredCarrierStrategy;
use AIArmada\Shipping\ShippingManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Aggregates rates from multiple carriers and applies selection rules.
 */
class RateShoppingEngine implements ShopRates
{
    protected RateSelectionStrategyInterface $strategy;

    private readonly RateCache $cache;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly ShippingManager $shippingManager,
        protected readonly array $config = []
    ) {
        $this->strategy = $this->resolveStrategy();
        $this->cache = new RateCache;
    }

    /**
     * Get all available rates from all carriers.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return list<RateQuoteData>
     */
    public function getAllRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): array {
        $cacheKey = $this->buildCacheKey($origin, $destination, $packages, $options);
        $cacheTtl = $this->config['cache_ttl'] ?? 300;

        // If options cannot be safely hashed (objects/resources), caching can return incorrect rates.
        if ($cacheKey === null) {
            return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options)->values()->all();
        }

        if ($cacheTtl > 0) {
            return $this->cache->remember($this->cacheTags(), $cacheKey, $cacheTtl, fn (): array => $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options)->values()->all());
        }

        return $this->fetchRatesFromAllCarriers($origin, $destination, $packages, $options)->values()->all();
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

        if ($allRates === []) {
            return $this->getFallbackRate($origin, $destination, $packages, $options);
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
            if (! $this->shippingManager->hasDriver($carrierCode)) {
                continue;
            }

            if ($this->isCircuitOpen($carrierCode)) {
                continue;
            }

            try {
                $driver = $this->shippingManager->driver($carrierCode);

                if (! $driver->servicesDestination($destination)) {
                    $this->recordCarrierSuccess($carrierCode);

                    continue;
                }

                $carrierRates = $driver->getRates($origin, $destination, $packages, $options);
                $this->recordCarrierSuccess($carrierCode);
                $rates = $rates->merge($carrierRates);
            } catch (Throwable $e) {
                $this->recordCarrierFailure($carrierCode);
                report($e);
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
     *
     * Taggable stores flush by tag. Other stores rotate a shared scope
     * generation, invalidating cached quotes across application instances.
     */
    public function clearCache(): void
    {
        $this->cache->clear($this->cacheTags());
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
        $carrierCodes = array_values(array_filter(
            $this->shippingManager->getAvailableDrivers(),
            fn (string $carrierCode): bool => ! $this->isCircuitOpen($carrierCode),
        ));

        if ($carrierCodes === []) {
            return collect();
        }

        // If options are not concurrency-safe (contain objects/resources), fall back to sequential calls.
        if ((bool) config('shipping.features.owner.enabled', false)
            || OwnerContext::hasOverride()
            || OwnerScopeOverride::suppressIncludeGlobal()
            || ! $this->isConcurrencySafe($options)) {
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

                        if (! $driver->servicesDestination($destination)) {
                            app(self::class)->recordCarrierSuccess($carrierCode);

                            return [];
                        }

                        $rates = $driver->getRates($origin, $destination, $packages, $options);

                        app(self::class)->recordCarrierSuccess($carrierCode);

                        return $rates;
                    } catch (Throwable $e) {
                        // Log error but return empty - other carriers may succeed
                        app(self::class)->recordCarrierFailure($carrierCode);
                        report($e);

                        return [];
                    }
                },
            ];
        })->all();

        // Execute all carrier calls concurrently. The timeout is enforced by the
        // process/fork drivers; the sync driver runs tasks inline.
        $results = Concurrency::run($tasks, $this->concurrencyTimeout());

        // Merge all successful results
        $rates = collect();
        foreach ($results as $carrierRates) {
            if (is_array($carrierRates)) {
                $rates = $rates->merge($carrierRates);
            }
        }

        return $rates->sortBy('rate');
    }

    /**
     * Record a successful carrier call, closing a tripped circuit.
     *
     * Public so concurrent child processes can report back through the
     * container; prefer the rate methods over calling this directly.
     */
    public function recordCarrierSuccess(string $carrierCode): void
    {
        $this->cache->forgetFailure($this->circuitCacheKey($carrierCode));
    }

    /**
     * Record a failed carrier call, tripping the circuit past threshold.
     *
     * Public so concurrent child processes can report back through the
     * container; prefer the rate methods over calling this directly.
     */
    public function recordCarrierFailure(string $carrierCode): void
    {
        $threshold = $this->circuitFailureThreshold();

        if ($threshold <= 0) {
            return;
        }

        $this->cache->recordFailure($this->circuitCacheKey($carrierCode), $this->circuitCooldownSeconds());
    }

    protected function isCircuitOpen(string $carrierCode): bool
    {
        $threshold = $this->circuitFailureThreshold();

        if ($threshold <= 0) {
            return false;
        }

        return $this->cache->failures($this->circuitCacheKey($carrierCode)) >= $threshold;
    }

    protected function circuitCacheKey(string $carrierCode): string
    {
        return 'shipping:circuit:' . $this->ownerScopeKey() . ':' . $carrierCode . ':failures';
    }

    protected function circuitFailureThreshold(): int
    {
        return (int) ($this->config['circuit_failure_threshold'] ?? 3);
    }

    protected function circuitCooldownSeconds(): int
    {
        return max(1, (int) ($this->config['circuit_cooldown_seconds'] ?? 300));
    }

    protected function concurrencyTimeout(): int
    {
        return max(1, (int) ($this->config['concurrency_timeout'] ?? 30));
    }

    /**
     * @return array<int, string>
     */
    protected function cacheTags(): array
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return ['shipping', 'shipping:rates'];
        }

        return ['shipping-rates-owner-' . $this->ownerScopeKey()];
    }

    /**
     * Get fallback rate when no carrier rates available.
     *
     * @param  array<PackageData>  $packages
     */
    protected function getFallbackRate(AddressData $origin, AddressData $destination, array $packages, array $options): ?RateQuoteData
    {
        $fallbackEnabled = $this->config['fallback_to_manual'] ?? true;

        if (! $fallbackEnabled || ! $this->shippingManager->hasDriver('manual')) {
            return null;
        }

        try {
            $driver = $this->shippingManager->driver('manual');

            if (! $driver->servicesDestination($destination)) {
                return null;
            }

            return $driver->getRates(
                $origin,
                $destination,
                $packages,
                $options,
            )[0] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

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
        $optionsHash = $this->hashForCache($options);
        if ($optionsHash === null) {
            return null;
        }

        return 'shipping:rates:list:' . md5(serialize([
            'owner' => $this->ownerScopeKey(),
            'owner_enabled' => (bool) config('shipping.features.owner.enabled', false),
            'include_global' => ! OwnerScopeOverride::suppressIncludeGlobal() && (bool) config('shipping.features.owner.include_global', false),
            'origin' => $origin->toArray(),
            'destination' => $destination->toArray(),
            'packages' => array_map(
                static fn (PackageData $package): array => $package->toArray(),
                $packages,
            ),
            'options' => $optionsHash,
        ]));
    }

    private function ownerScopeKey(): string
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return OwnerScopeKey::GLOBAL;
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            'Shipping rate cache access requires an owner context or explicit global context.',
        );

        return OwnerScopeKey::forOwner($owner);
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
     */
    protected function resolveStrategy(): RateSelectionStrategyInterface
    {
        return match ($this->config['strategy'] ?? 'cheapest') {
            'fastest' => new FastestRateStrategy,
            'balanced' => new BalancedRateStrategy,
            'preferred' => new PreferredCarrierStrategy($this->config['carrier_priority'] ?? []),
            default => new CheapestRateStrategy,
        };
    }
}
