---
title: Configuration
---

# Configuration

All configuration is in `config/shipping.php`. Below is a complete reference.

## Database

```php
'database' => [
    // Table name prefix
    'table_prefix' => 'shipping_',
    
    // JSON column type (json or jsonb for PostgreSQL)
    'json_column_type' => 'json',
    
    // Override individual table names
    'tables' => [
        'shipments' => null,           // Uses prefix + 'shipments'
        'shipment_items' => null,
        'shipment_events' => null,
        'shipment_labels' => null,
        'zones' => null,
        'rates' => null,
        'return_authorizations' => null,
        'return_authorization_items' => null,
    ],
],
```

## Defaults

```php
'defaults' => [
    // Default currency for shipping costs
    'currency' => env('SHIPPING_CURRENCY', 'MYR'),
    
    // Weight unit: 'g' (grams) or 'kg' (kilograms)
    'weight_unit' => 'g',
    
    // Dimension unit: 'cm' or 'in'
    'dimension_unit' => 'cm',
    
    // Default origin address
    'origin' => [
        'line1' => env('SHIPPING_ORIGIN_LINE1'),
        'line2' => env('SHIPPING_ORIGIN_LINE2'),
        'city' => env('SHIPPING_ORIGIN_CITY'),
        'state' => env('SHIPPING_ORIGIN_STATE'),
        'postcode' => env('SHIPPING_ORIGIN_POSTCODE'),
        'country' => env('SHIPPING_ORIGIN_COUNTRY', 'MY'),
    ],
],
```

## Owner Scoping (Multi-Tenancy)

```php
'features' => [
    'owner' => [
        // Enable tenant isolation
        'enabled' => false,
        
        // Include global (null owner) records
        'include_global' => true,
    ],
],
```

When enabled, shipments are automatically scoped to the current tenant using the `commerce-support` package's `OwnerContext`.

## Default Driver

```php
// Default shipping driver
'default' => env('SHIPPING_DRIVER', 'manual'),
```

## Drivers

### Manual Driver

For manual fulfillment without carrier integration:

```php
'drivers' => [
    'manual' => [
        'name' => 'Manual',
        'base_rate' => 1000, // RM10.00 (in cents)
    ],
],
```

### Flat Rate Driver

Tiered flat-rate shipping:

```php
'drivers' => [
    'flat_rate' => [
        'name' => 'Flat Rate',
        'rates' => [
            'standard' => [
                'name' => 'Standard Shipping',
                'amount' => 800,           // RM8.00
                'days_min' => 3,
                'days_max' => 5,
            ],
            'express' => [
                'name' => 'Express Shipping',
                'amount' => 1500,          // RM15.00
                'days_min' => 1,
                'days_max' => 2,
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

## Rate Shopping

```php
'rate_shopping' => [
    // Enable rate comparison across carriers
    'enabled' => true,
    
    // Rate selection strategy
    'strategy' => 'cheapest', // cheapest, fastest, preferred_carrier, balanced
    
    // Cache duration in minutes
    'cache_ttl' => 5,
    
    // Fallback driver if rate shopping fails
    'fallback_driver' => 'manual',
    
    // Preferred carrier for 'preferred_carrier' strategy
    'preferred_carrier' => null,
],
```

### Available Strategies

| Strategy | Description |
|----------|-------------|
| `cheapest` | Select the lowest cost option |
| `fastest` | Select the fastest delivery option |
| `preferred_carrier` | Prefer a specific carrier, fall back to cheapest |
| `balanced` | Balance between cost and speed |

## Free Shipping

```php
'free_shipping' => [
    // Enable free shipping threshold
    'enabled' => false,
    
    // Minimum cart value for free shipping (in cents)
    'threshold' => 15000, // RM150.00
    
    // Currency symbol for messages
    'currency' => 'RM',
],
```

## Tracking

```php
'tracking' => [
    // Sync interval in seconds
    'sync_interval' => 3600, // 1 hour
    
    // Maximum shipment age to sync (days)
    'max_sync_age_days' => 30,
    
    // Batch size for bulk sync
    'batch_size' => 100,
],
```

## API Settings

```php
// API timeout in seconds
'api_timeout' => 30,

// Number of retry attempts
'api_retries' => 3,
```

## Complete Example

```php
<?php

return [
    'database' => [
        'table_prefix' => 'shipping_',
        'json_column_type' => 'json',
        'tables' => [],
    ],

    'defaults' => [
        'currency' => env('SHIPPING_CURRENCY', 'MYR'),
        'weight_unit' => 'g',
        'dimension_unit' => 'cm',
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

    'default' => 'manual',

    'drivers' => [
        'manual' => [
            'name' => 'Manual',
            'base_rate' => 1000,
        ],
        'flat_rate' => [
            'name' => 'Flat Rate',
            'rates' => [
                'standard' => [
                    'name' => 'Standard',
                    'amount' => 800,
                    'days_min' => 3,
                    'days_max' => 5,
                ],
            ],
        ],
    ],

    'rate_shopping' => [
        'enabled' => true,
        'strategy' => 'cheapest',
        'cache_ttl' => 5,
        'fallback_driver' => 'manual',
    ],

    'free_shipping' => [
        'enabled' => true,
        'threshold' => 15000,
        'currency' => 'RM',
    ],

    'tracking' => [
        'sync_interval' => 3600,
        'max_sync_age_days' => 30,
        'batch_size' => 100,
    ],

    'api_timeout' => 30,
    'api_retries' => 3,
];
```
