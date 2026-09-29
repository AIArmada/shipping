---
title: Configuration
---

# Configuration

All configuration is in `config/shipping.php`. Each section below shows the
shipped default for that key only.

## Database

The shipped config builds every table name from a single prefix variable, so
setting `SHIPPING_TABLE_PREFIX` (or `COMMERCE_TABLE_PREFIX`) renames all tables
at once.

```php
$tablePrefix = env('SHIPPING_TABLE_PREFIX', env('COMMERCE_TABLE_PREFIX', ''));

return [
'database' => [
    'table_prefix' => $tablePrefix,
    'tables' => [
        'shipments' => $tablePrefix . 'shipments',
        'shipment_items' => $tablePrefix . 'shipment_items',
        'shipment_labels' => $tablePrefix . 'shipment_labels',
        'shipment_events' => $tablePrefix . 'shipment_events',
        'shipping_zones' => $tablePrefix . 'shipping_zones',
        'shipping_rates' => $tablePrefix . 'shipping_rates',
        'return_authorizations' => $tablePrefix . 'return_authorizations',
        'return_authorization_items' => $tablePrefix . 'return_authorization_items',
        'shipment_operations' => $tablePrefix . 'shipment_operations',
    ],
    'json_column_type' => env('SHIPPING_JSON_COLUMN_TYPE', 'jsonb'),
],
];
```

> **info**
> `shipment_operations` is read by the package (`config('shipping.database.tables.shipment_operations')`)
> but is not defined in the shipped `tables` array, so `ShipmentOperation` falls
> back to the default name. Add `'shipment_operations' => $tablePrefix . 'shipment_operations'`
> if you need a prefixed name for it.

## Defaults

```php
'defaults' => [
    'currency' => 'MYR',
    'weight_unit' => 'g',
    'reference_prefix' => env('SHIPPING_REFERENCE_PREFIX', 'SHP-'),
    'origin' => [
        'name' => env('SHIPPING_ORIGIN_NAME', env('APP_NAME', 'Store')),
        'phone' => env('SHIPPING_ORIGIN_PHONE', ''),
        'line1' => env('SHIPPING_ORIGIN_LINE1', ''),
        'line2' => env('SHIPPING_ORIGIN_LINE2', ''),
        'postcode' => env('SHIPPING_ORIGIN_POSTCODE', ''),
        'country' => env('SHIPPING_ORIGIN_COUNTRY', 'MY'),
        'state' => env('SHIPPING_ORIGIN_STATE'),
        'city' => env('SHIPPING_ORIGIN_CITY'),
    ],
],
```

`defaults.currency` is a literal `'MYR'`, not env-driven — change it in the
published file. `weight_unit` is a literal `'g'`.

## Owner Scoping (Multi-Tenancy)

```php
'features' => [
    'owner' => [
        'enabled' => env('SHIPPING_OWNER_ENABLED', false),
        'include_global' => env('SHIPPING_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('SHIPPING_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],
],
```

When enabled, shipments are automatically scoped to the current tenant using the `commerce-support` package's `OwnerContext`.

## Default Driver

```php
// Default shipping driver (note the `drivers.` prefix)
'drivers' => [
    'default' => env('SHIPPING_DRIVER', 'manual'),
],
```

The instance-level override is request-scoped and Octane-safe:

```php
use AIArmada\Shipping\Facades\Shipping;

Shipping::setDefaultDriver('flat_rate');
```

## Drivers

### Manual Driver

For manual fulfillment without carrier integration:

```php
'drivers' => [
    'manual' => [
        'driver' => 'manual',
        'name' => 'Manual Shipping',
        'default_rate' => 1000, // RM10.00 in minor units
        'estimated_days' => 3,
        'free_shipping_threshold' => null,
    ],
],
```

### Flat Rate Driver

Named flat rates:

```php
'drivers' => [
    'flat_rate' => [
        'driver' => 'flat_rate',
        'name' => 'Flat Rate Shipping',
        'rates' => [
            'standard' => [
                'name' => 'Standard Delivery',
                'rate' => 800, // RM8.00 in minor units
                'estimated_days' => 3,
            ],
            'express' => [
                'name' => 'Express Delivery',
                'rate' => 1500, // RM15.00 in minor units
                'estimated_days' => 1,
            ],
        ],
    ],
],
```

### Zone Driver

```php
'drivers' => [
    'zone' => [
        'driver' => 'zone',
        'name' => 'Zone-Based Shipping',
    ],
],
```

### Custom Drivers

Register custom drivers in a service provider:

```php
use AIArmada\Shipping\Facades\Shipping;

Shipping::extend('jnt', function ($container) {
    return new JntShippingDriver(
        config('shipping.drivers.jnt')
    );
});
```

## Zone Resolution

```php
'zone_resolution' => [
    'strategy' => env('SHIPPING_ZONE_RESOLUTION_STRATEGY', 'geo'),
],
```

The strategy key must identify a strategy registered in `ZoneResolutionStrategyRegistry`; `geo` is registered by default.

Zone candidates are owner-scoped via `forOwner()` with `shipping.features.owner.include_global` when owner mode is enabled. `AddressData` requires `name`, `phone`, `line1`, and `postcode`:

```php
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Services\ShippingZoneResolver;

$zone = app(ShippingZoneResolver::class)->resolve(
    AddressData::from([
        'name' => 'Jane Doe',
        'phone' => '+60123456789',
        'line1' => '1 Jalan Test',
        'postcode' => '47800',
        'country' => 'MY',
        'state' => 'Selangor',
    ]),
);
```

## Rate Shopping

```php
'rate_shopping' => [
    'strategy' => 'cheapest', // cheapest, fastest, preferred
    'cache_ttl' => 300, // seconds
    'fallback_to_manual' => true,
    'concurrency_timeout' => 30, // seconds per carrier fan-out; process/fork drivers only
    'circuit_failure_threshold' => 3, // consecutive failures before a carrier is skipped; 0 disables
    'circuit_cooldown_seconds' => 300, // seconds a tripped carrier stays skipped
    'carrier_priority' => [
        // 'jnt' => 1,
        // 'poslaju' => 2,
    ],
],
```

### Available Strategies

| Strategy | Description |
|----------|-------------|
| `cheapest` | Select the lowest cost option |
| `fastest` | Select the fastest delivery option |
| `preferred` | Use `carrier_priority`, falling back to the cheapest quote |

## Free Shipping

```php
'free_shipping' => [
    'enabled' => false,
    'threshold' => 15000, // RM150.00 in minor units
],
// Note: the threshold policy resolves its display currency from
// `shipping.defaults.currency`, not from this section.
```

> **info**
> There is no `free_shipping.currency` config key. `ShippingServiceProvider`
> injects `currency` into the free-shipping config at runtime from
> `shipping.defaults.currency` when it is not already set, so the value is an ISO
> 4217 code (`MYR`), not a display symbol.

## Zone Resolution Strategy Registry

The `ZoneResolutionStrategyRegistry` manages pluggable strategies for resolving shipping zones
from an address. The default `GeoZoneResolutionStrategy` matches by country, state, city, and
postcode. Register custom strategies in a service provider:

```php
use AIArmada\Shipping\Support\ZoneResolutionStrategyRegistry;
use App\Shipping\Strategies\B2BZoneResolutionStrategy;

$registry = app(ZoneResolutionStrategyRegistry::class);
$registry->register(new B2BZoneResolutionStrategy(...));

// Retrieve a strategy by key
$strategy = $registry->get('geo');
```

## Free Shipping Policy Registry

The `FreeShippingPolicyRegistry` manages pluggable free-shipping evaluation policies. The default
`ThresholdFreeShippingPolicy` evaluates based on cart subtotal. Register custom policies in a service provider:

```php
use AIArmada\Shipping\Support\FreeShippingPolicyRegistry;
use App\Shipping\Policies\MemberFreeShippingPolicy;

$registry = app(FreeShippingPolicyRegistry::class);
$registry->register(new MemberFreeShippingPolicy(...));

// Retrieve a policy by key
$policy = $registry->get('threshold');
```

## Tracking

```php
'tracking' => [
    'sync_interval' => 3600, // 1 hour in seconds
    'max_tracking_age' => 30, // days to keep syncing
],
```

## HTTP

```php
'http' => [
    'timeout' => env('SHIPPING_API_TIMEOUT', 30), // seconds
    'retries' => env('SHIPPING_API_RETRIES', 3),
    'base_delay_ms' => env('SHIPPING_API_BASE_DELAY_MS', 100),
],
```
