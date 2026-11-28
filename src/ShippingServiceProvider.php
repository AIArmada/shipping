<?php

declare(strict_types=1);

namespace AIArmada\Shipping;

use AIArmada\Orders\Contracts\FulfillmentHandler;
use AIArmada\Orders\Contracts\PackageReturnVerifier;
use AIArmada\Shipping\Contracts\ZoneRateReader;
use AIArmada\Shipping\Drivers\FlatRateShippingDriver;
use AIArmada\Shipping\Drivers\ManualShippingDriver;
use AIArmada\Shipping\Drivers\NullShippingDriver;
use AIArmada\Shipping\Drivers\ZoneBasedShippingDriver;
use AIArmada\Shipping\Features\Returns\Actions\PackageReturns;
use AIArmada\Shipping\Features\Shipments\Actions\OrderFulfillmentHandler;
use AIArmada\Shipping\Features\Shipments\Actions\ShipmentService;
use AIArmada\Shipping\Platform\ExtensionRegistry;
use AIArmada\Shipping\Platform\FeatureCatalog;
use AIArmada\Shipping\Platform\FeatureModule;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ShippingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('shipping')
            ->hasConfigFile()
            ->runsMigrations()
            ->discoversMigrations();
    }

    public function packageRegistered(): void
    {
        $catalog = FeatureCatalog::load(dirname(__DIR__), __NAMESPACE__);
        $this->app->singleton(ExtensionRegistry::class, fn (Application $app): ExtensionRegistry => new ExtensionRegistry($app, $catalog['extensions']));

        foreach (array_keys($catalog['features']) as $name) {
            $module = $this->app->make(__NAMESPACE__ . '\\Features\\' . $name . '\\FeatureModule');

            if ($module instanceof FeatureModule) {
                $module->register($this->app);
            }
        }

        $this->app->scoped(ShippingManager::class, function ($app): ShippingManager {
            return new ShippingManager($app);
        });

        $this->app->alias(ShippingManager::class, 'shipping');

        $this->app->bind('shipping.driver.null', fn (): NullShippingDriver => new NullShippingDriver);
        $this->app->bind('shipping.driver.manual', fn (Application $app): ManualShippingDriver => new ManualShippingDriver((array) $app->make('config')->get('shipping.drivers.manual', [])));
        $this->app->bind('shipping.driver.flat_rate', fn (Application $app): FlatRateShippingDriver => new FlatRateShippingDriver((array) $app->make('config')->get('shipping.drivers.flat_rate', [])));
        $this->app->bind('shipping.driver.zone', fn (Application $app): ZoneBasedShippingDriver => new ZoneBasedShippingDriver($app->make(ZoneRateReader::class), (array) $app->make('config')->get('shipping.drivers.zone', [])));

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

        $this->app->bind(PackageReturnVerifier::class, PackageReturns::class);

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
        $this->loadRoutesFrom(__DIR__ . '/Features/Shipments/EntryPoints/Routes/web.php');

        foreach (array_keys(FeatureCatalog::load(dirname(__DIR__), __NAMESPACE__)['features']) as $name) {
            $module = $this->app->make(__NAMESPACE__ . '\\Features\\' . $name . '\\FeatureModule');

            if ($module instanceof FeatureModule) {
                $module->boot($this->app);
            }
        }

    }
}
