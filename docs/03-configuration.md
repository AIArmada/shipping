---
title: Configuration
---

# Configuration

All configuration is in `config/shipping.php`. Below is a complete reference.

## Database

```php
'database' => [
    // Table name prefix (SHIPPING_TABLE_PREFIX, default none)
    'table_prefix' => '',

    // Override individual table names
    'tables' => [
        'shipments' => null,           // Uses prefix + 'shipments'
        'shipment_items' => null,
        'shipment_events' => null,
        'shipment_labels' => null,
        'shipping_zones' => null,
        'shipping_rates' => null,
        'return_authorizations' => null,
        'return_authorization_items' => null,
        'shipment_operations' => null,
    ],
],
```

## Defaults

```php
'defaults' => [
    'currency' => 'MYR',
    'weight_unit' => 'g',
    'origin' => [
        'name' => env('SHIPPING_ORIGIN_NAME', env('APP_NAME', 'Store')),
        'phone' => env('SHIPPING_ORIGIN_PHONE', ''),
        'line1' => env('SHIPPING_ORIGIN_LINE1'),
        'line2' => env('SHIPPING_ORIGIN_LINE2'),
        'postcode' => env('SHIPPING_ORIGIN_POSTCODE'),
        'country' => env('SHIPPING_ORIGIN_COUNTRY', 'MY'),
        'state' => env('SHIPPING_ORIGIN_STATE'),
        'city' => env('SHIPPING_ORIGIN_CITY'),
    ],
],
```

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
        'name' => 'Manual Shipping',
        'default_rate' => 1000, // RM10.00 (in cents)
        'estimated_days' => 3,
        'free_shipping_threshold' => null,
    ],
],
```

### Flat Rate Driver

Tiered flat-rate shipping:

```php
'drivers' => [
    'flat_rate' => [
        'name' => 'Flat Rate Shipping',
        'rates' => [
            'standard' => [
                'name' => 'Standard Delivery',
                'rate' => 800,           // RM8.00
                'estimated_days' => 3,
            ],
            'express' => [
                'name' => 'Express Delivery',
                'rate' => 1500,          // RM15.00
                'estimated_days' => 1,
            ],
        ],
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

Zone candidates are owner-scoped via `forOwner()` with `shipping.features.owner.include_global` when owner mode is enabled:

```php
use AIArmada\Shipping\Services\ShippingZoneResolver;
use AIArmada\Shipping\Data\AddressData;

$zone = app(ShippingZoneResolver::class)->resolve(
    AddressData::from(['name' => 'John Doe', 'phone' => '+60123456789', 'line1' => '456 Customer Ave', 'country' => 'MY', 'state' => 'Selangor', 'postcode' => '47800']),
);
```

## Rate Shopping

```php
'rate_shopping' => [
    // Rate selection strategy
    'strategy' => 'cheapest', // cheapest, fastest, preferred

    // Cache duration in seconds
    'cache_ttl' => 300,

    // Fall back to the manual driver when carriers fail
    'fallback_to_manual' => true,

    // Lower values have higher priority
    'carrier_priority' => [],
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
    // Enable free shipping threshold
    'enabled' => false,

    // Minimum cart value for free shipping (in cents)
    'threshold' => 15000, // RM150.00
],
// Note: the threshold policy resolves its display currency from
// `shipping.defaults.currency`, not from this section.
```

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
    // Sync interval in seconds
    'sync_interval' => 3600, // 1 hour

    // Maximum shipment age to sync (days)
    'max_tracking_age' => 30,
],
```

## HTTP Settings

```php
'http' => [
    // API timeout in seconds
    'timeout' => env('SHIPPING_API_TIMEOUT', 30),

    // Number of retry attempts
    'retries' => env('SHIPPING_API_RETRIES', 3),

    // Base delay between retries in milliseconds
    'base_delay_ms' => env('SHIPPING_API_BASE_DELAY_MS', 100),
],
```

## Complete Example

```php
<?php

return [
    'database' => [
        'table_prefix' => '',
        'tables' => [],
    ],

    'defaults' => [
        'currency' => 'MYR',
        'weight_unit' => 'g',
        'origin' => [
            'line1' => env('SHIPPING_ORIGIN_LINE1'),
            'city' => env('SHIPPING_ORIGIN_CITY'),
            'state' => env('SHIPPING_ORIGIN_STATE'),
            'postcode' => env('SHIPPING_ORIGIN_POSTCODE'),
            'country' => env('SHIPPING_ORIGIN_COUNTRY', 'MY'),
        ],
    ],

    'features' => [
        'owner' => [
            'enabled' => true,
            'include_global' => false,
        ],
    ],

    'drivers' => [
        'default' => 'manual',
        'manual' => [
            'name' => 'Manual Shipping',
            'default_rate' => 1000,
            'estimated_days' => 3,
        ],
        'flat_rate' => [
            'name' => 'Flat Rate Shipping',
            'rates' => [
                'standard' => [
                    'name' => 'Standard Delivery',
                    'rate' => 800,
                    'estimated_days' => 3,
                ],
            ],
        ],
    ],

    'rate_shopping' => [
        'strategy' => 'cheapest',
        'cache_ttl' => 300,
        'fallback_to_manual' => true,
        'carrier_priority' => [],
    ],

    'free_shipping' => [
        'enabled' => true,
        'threshold' => 15000,
    ],

    'tracking' => [
        'sync_interval' => 3600,
        'max_tracking_age' => 30,
    ],

    'http' => [
        'timeout' => 30,
        'retries' => 3,
        'base_delay_ms' => 100,
    ],
];
```
