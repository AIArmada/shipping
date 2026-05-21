<?php

declare(strict_types=1);

namespace AIArmada\Shipping;

use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Drivers\FlatRateShippingDriver;
use AIArmada\Shipping\Drivers\ManualShippingDriver;
use AIArmada\Shipping\Drivers\NullShippingDriver;
use AIArmada\Shipping\Drivers\ZoneBasedShippingDriver;
use AIArmada\Shipping\Services\ShippingZoneResolver;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Manages shipping drivers using Laravel's Manager pattern.
 */
class ShippingManager
{
    /**
     * The application instance.
     */
    protected Container $container;

    /**
     * The registered custom driver creators.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    /**
     * The registered status mappers.
     *
     * @var array<string, StatusMapperInterface>
     */
    protected array $statusMappers = [];

    /**
     * The resolved driver instances.
     *
     * @var array<string, ShippingDriverInterface>
     */
    protected array $drivers = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  array<mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->{$method}(...$parameters);
    }

    /**
     * Get a shipping driver instance.
     */
    public function driver(?string $driver = null): ShippingDriverInterface
    {
        $driver ??= $this->getDefaultDriver();

        // The zone driver injects ShippingZoneResolver which is scoped (Octane-safe).
        // Do not cache it in the singleton driver map so each request gets a fresh resolver.
        if ($driver === 'zone') {
            return $this->createDriver($driver);
        }

        if (! isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->container->get('config')->get('shipping.drivers.default', 'manual');
    }

    /**
     * Set the default driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->container->get('config')->set('shipping.drivers.default', $name);
    }

    /**
     * Register a custom driver creator.
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Register a status mapper for a carrier.
     */
    public function registerStatusMapper(StatusMapperInterface $mapper): self
    {
        $this->statusMappers[$mapper->getCarrierCode()] = $mapper;

        return $this;
    }

    /**
     * Get registered status mapper for a carrier.
     */
    public function getStatusMapper(string $carrierCode): ?StatusMapperInterface
    {
        return $this->statusMappers[$carrierCode] ?? null;
    }

    /**
     * Get all registered driver names.
     *
     * @return array<string>
     */
    public function getAvailableDrivers(): array
    {
        $configuredDrivers = array_keys(
            $this->container->get('config')->get('shipping.drivers', [])
        );

        $customDrivers = array_keys($this->customCreators);

        return array_unique(array_merge($configuredDrivers, $customDrivers));
    }

    /**
     * Get all drivers that service a destination.
     *
     * @return Collection<int, ShippingDriverInterface>
     */
    public function getDriversForDestination(AddressData $destination): Collection
    {
        return collect($this->getAvailableDrivers())
            ->map(fn (string $name) => $this->driver($name))
            ->filter(fn (ShippingDriverInterface $driver) => $driver->servicesDestination($destination))
            ->values();
    }

    /**
     * Check if a driver is registered.
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->customCreators[$driver])
            || $this->container->get('config')->has("shipping.drivers.{$driver}");
    }

    /**
     * Create a new driver instance.
     */
    protected function createDriver(string $driver): ShippingDriverInterface
    {
        // Check for custom creators first
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        // Check for built-in drivers (convert snake_case to PascalCase)
        $method = 'create' . str_replace('_', '', ucwords($driver, '_')) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        throw new InvalidArgumentException("Driver [{$driver}] not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $driver): ShippingDriverInterface
    {
        return $this->customCreators[$driver]($this->container);
    }

    /**
     * Create the null driver (for testing).
     */
    protected function createNullDriver(): ShippingDriverInterface
    {
        return new NullShippingDriver;
    }

    /**
     * Create the manual shipping driver.
     */
    protected function createManualDriver(): ShippingDriverInterface
    {
        $config = $this->container->get('config')->get('shipping.drivers.manual', []);

        return new ManualShippingDriver($config);
    }

    /**
     * Create the flat rate shipping driver.
     */
    protected function createFlatRateDriver(): ShippingDriverInterface
    {
        $config = $this->container->get('config')->get('shipping.drivers.flat_rate', []);

        return new FlatRateShippingDriver($config);
    }

    /**
     * Create the zone-based shipping driver.
     */
    protected function createZoneDriver(): ShippingDriverInterface
    {
        $config = $this->container->get('config')->get('shipping.drivers.zone', []);
        $resolver = $this->container->make(ShippingZoneResolver::class);

        return new ZoneBasedShippingDriver($resolver, $config);
    }
}
