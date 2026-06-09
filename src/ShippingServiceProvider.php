<?php

declare(strict_types=1);

namespace AIArmada\Shipping;

use AIArmada\Cart\Conditions\ConditionProviderRegistry;
use AIArmada\Orders\Contracts\FulfillmentHandler;
use AIArmada\Shipping\Cart\ShippingConditionProvider;
use AIArmada\Shipping\Integrations\OrderFulfillmentHandler;
use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShippingZone;
use AIArmada\Shipping\Policies\ReturnAuthorizationPolicy;
use AIArmada\Shipping\Policies\ShipmentPolicy;
use AIArmada\Shipping\Policies\ShippingZonePolicy;
use AIArmada\Shipping\Services\FreeShippingEvaluator;
use AIArmada\Shipping\Services\RateShoppingEngine;
use AIArmada\Shipping\Services\ShipmentService;
use AIArmada\Shipping\Services\ShippingZoneResolver;
use AIArmada\Shipping\Strategies\GeoZoneResolutionStrategy;
use AIArmada\Shipping\Strategies\ThresholdFreeShippingPolicy;
use AIArmada\Shipping\Support\FreeShippingPolicyRegistry;
use AIArmada\Shipping\Support\ZoneResolutionStrategyRegistry;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ShippingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('shipping')
            ->hasConfigFile()
            ->hasRoute('web')
            ->runsMigrations()
            ->discoversMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ShippingManager::class, function ($app) {
            return new ShippingManager($app);
        });

        $this->app->alias(ShippingManager::class, 'shipping');

        // Register free shipping policy registry with default threshold policy
        $this->app->singleton(FreeShippingPolicyRegistry::class, function ($app): FreeShippingPolicyRegistry {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.free_shipping', []);
            $config['currency'] ??= (string) $app->make('config')->get('shipping.defaults.currency', 'MYR');

            $registry = new FreeShippingPolicyRegistry;
            $registry->register(new ThresholdFreeShippingPolicy($config));

            return $registry;
        });

        // Register zone resolution strategy registry with default geo strategy
        $this->app->singleton(ZoneResolutionStrategyRegistry::class, function (): ZoneResolutionStrategyRegistry {
            $registry = new ZoneResolutionStrategyRegistry;
            $registry->register(new GeoZoneResolutionStrategy);

            return $registry;
        });

        // Scoped so per-request memoization cache never bleeds across Octane requests
        $this->app->scoped(ShippingZoneResolver::class);

        $this->app->singleton(RateShoppingEngine::class, function ($app): RateShoppingEngine {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.rate_shopping', []);

            return new RateShoppingEngine($app->make(ShippingManager::class), $config);
        });

        $this->app->singleton(FreeShippingEvaluator::class, function ($app): FreeShippingEvaluator {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('shipping.free_shipping', []);
            $config['currency'] ??= (string) $app->make('config')->get('shipping.defaults.currency', 'MYR');

            return new FreeShippingEvaluator(
                $app->make(FreeShippingPolicyRegistry::class),
                $config,
            );
        });

        if (class_exists(ConditionProviderRegistry::class)) {
            $this->app->singleton(ShippingConditionProvider::class);
            $this->app->make(ConditionProviderRegistry::class)
                ->register(ShippingConditionProvider::class);
        }

        $this->registerOrdersIntegration();
    }

    /**
     * Register the orders package integration when available.
     */
    protected function registerOrdersIntegration(): void
    {
        if (! interface_exists(FulfillmentHandler::class)) {
            return;
        }

        $this->app->bind(
            FulfillmentHandler::class,
            function ($app): OrderFulfillmentHandler {
                return new OrderFulfillmentHandler(
                    $app->make(ShippingManager::class),
                    $app->make(ShipmentService::class),
                );
            }
        );
    }

    public function bootingPackage(): void
    {
        $this->registerPolicies();
        $this->registerEventListeners();
        $this->registerCommands();
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(ShippingZone::class, ShippingZonePolicy::class);
        Gate::policy(ReturnAuthorization::class, ReturnAuthorizationPolicy::class);
    }

    protected function registerEventListeners(): void
    {
        // Event listeners will be registered here
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Commands will be registered here
            ]);
        }
    }
}
