<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Tests;

use AIArmada\CommerceSupport\SupportServiceProvider;
use AIArmada\Shipping\ShippingServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            SupportServiceProvider::class,
            ShippingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        Config::set('app.env', 'testing');
        Config::set('database.default', 'testing');
        Config::set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        Config::set('cache.default', 'array');
        Config::set('session.driver', 'array');
        Config::set('data.date_format', DATE_ATOM);
        Config::set('data.date_timezone', null);
        Config::set('shipping.features.owner.enabled', false);
        Config::set('shipping.database.json_column_type', 'json');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__ . '/../database/migrations'));
    }
}
