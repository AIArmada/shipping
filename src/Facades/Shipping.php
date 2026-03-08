<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Facades;

use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\ShippingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ShippingDriverInterface driver(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static \AIArmada\Shipping\ShippingManager extend(string $driver, \Closure $callback)
 * @method static array getAvailableDrivers()
 * @method static bool hasDriver(string $driver)
 *
 * @see ShippingManager
 */
class Shipping extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'shipping';
    }
}
