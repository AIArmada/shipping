<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates;

use AIArmada\Cart\Conditions\ConditionProviderRegistry;
use AIArmada\Shipping\Contracts\ZoneRateReader;
use AIArmada\Shipping\Features\Rates\Actions\CalculateShippingRate;
use AIArmada\Shipping\Features\Rates\Actions\WriteRateConfiguration;
use AIArmada\Shipping\Features\Rates\Api\CalculateRates;
use AIArmada\Shipping\Features\Rates\Api\EvaluateFreeShipping;
use AIArmada\Shipping\Features\Rates\Api\ManageRateConfiguration;
use AIArmada\Shipping\Features\Rates\Api\ShopRates;
use AIArmada\Shipping\Features\Rates\EntryPoints\Cart\ShippingConditionProvider;
use AIArmada\Shipping\Features\Rates\Models\ShippingRate;
use AIArmada\Shipping\Features\Rates\Models\ShippingZone;
use AIArmada\Shipping\Features\Rates\Policies\ShippingRatePolicy;
use AIArmada\Shipping\Features\Rates\Policies\ShippingZonePolicy;
use AIArmada\Shipping\Features\Rates\Queries\FreeShippingEvaluator;
use AIArmada\Shipping\Features\Rates\Queries\RateShoppingEngine;
use AIArmada\Shipping\Features\Rates\Queries\ShippingZoneResolver;
use AIArmada\Shipping\Features\Rates\Strategies\GeoZoneResolutionStrategy;
use AIArmada\Shipping\Features\Rates\Strategies\ThresholdFreeShippingPolicy;
use AIArmada\Shipping\Platform\FeatureModule as FeatureModuleContract;
use AIArmada\Shipping\Platform\FreeShippingPolicyRegistry;
use AIArmada\Shipping\Platform\ZoneResolutionStrategyRegistry;
use AIArmada\Shipping\ShippingManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(CalculateRates::class, CalculateShippingRate::class);
        $app->bind(ManageRateConfiguration::class, WriteRateConfiguration::class);

        // Register free shipping policy registry with default threshold policy
        $app->singleton(FreeShippingPolicyRegistry::class, function ($app): FreeShippingPolicyRegistry {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.free_shipping', []);
            $config['currency'] ??= (string) $app->make('config')->get('shipping.defaults.currency', 'MYR');

            $registry = new FreeShippingPolicyRegistry;
            $registry->register(new ThresholdFreeShippingPolicy($config));

            return $registry;
        });

        // Register zone resolution strategy registry with default geo strategy
        $app->singleton(ZoneResolutionStrategyRegistry::class, function (): ZoneResolutionStrategyRegistry {
            $registry = new ZoneResolutionStrategyRegistry;
            $registry->register(new GeoZoneResolutionStrategy);

            return $registry;
        });

        // Scoped so per-request memoization cache never bleeds across Octane requests
        $app->scoped(ShippingZoneResolver::class);
        $app->bind(ZoneRateReader::class, fn (Application $app): ZoneRateReader => $app->make(ShippingZoneResolver::class));

        $app->scoped(RateShoppingEngine::class, function ($app): RateShoppingEngine {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.rate_shopping', []);

            return new RateShoppingEngine($app->make(ShippingManager::class), $config);
        });

        $app->singleton(FreeShippingEvaluator::class, function ($app): FreeShippingEvaluator {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.free_shipping', []);
            $config['currency'] ??= (string) $app->make('config')->get('shipping.defaults.currency', 'MYR');

            return new FreeShippingEvaluator(
                $app->make(FreeShippingPolicyRegistry::class),
                $config,
            );
        });

        $app->bind(ShopRates::class, fn (Application $app): ShopRates => $app->make(RateShoppingEngine::class));
        $app->bind(EvaluateFreeShipping::class, fn (Application $app): EvaluateFreeShipping => $app->make(FreeShippingEvaluator::class));

        if (class_exists(ConditionProviderRegistry::class)) {
            $app->scoped(ShippingConditionProvider::class);
        }
    }

    public function boot(Application $app): void
    {
        Gate::policy(ShippingRate::class, ShippingRatePolicy::class);
        Gate::policy(ShippingZone::class, ShippingZonePolicy::class);

        if (class_exists(ConditionProviderRegistry::class)) {
            $app->make(ConditionProviderRegistry::class)
                ->register(ShippingConditionProvider::class);
        }
    }
}
