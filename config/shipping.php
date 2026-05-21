<?php

declare(strict_types=1);

$tablePrefix = env('SHIPPING_TABLE_PREFIX', env('COMMERCE_TABLE_PREFIX', ''));

$tables = [
    'shipments' => $tablePrefix . 'shipments',
    'shipment_items' => $tablePrefix . 'shipment_items',
    'shipment_labels' => $tablePrefix . 'shipment_labels',
    'shipment_events' => $tablePrefix . 'shipment_events',
    'shipping_zones' => $tablePrefix . 'shipping_zones',
    'shipping_rates' => $tablePrefix . 'shipping_rates',
    'return_authorizations' => $tablePrefix . 'return_authorizations',
    'return_authorization_items' => $tablePrefix . 'return_authorization_items',
];

return [
    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('SHIPPING_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
        'tables' => $tables,
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'currency' => 'MYR',
        'weight_unit' => 'g',
        'reference_prefix' => env('SHIPPING_REFERENCE_PREFIX', 'SHP-'),
        'origin' => [
            'name' => env('SHIPPING_ORIGIN_NAME', env('APP_NAME', 'Store')),
            'phone' => env('SHIPPING_ORIGIN_PHONE', ''),
            'line1' => env('SHIPPING_ORIGIN_LINE1', env('SHIPPING_ORIGIN_ADDRESS', '')),
            'line2' => env('SHIPPING_ORIGIN_LINE2', env('SHIPPING_ORIGIN_ADDRESS_2', '')),
            'postcode' => env('SHIPPING_ORIGIN_POSTCODE', env('SHIPPING_ORIGIN_POST_CODE', '')),
            'country' => env('SHIPPING_ORIGIN_COUNTRY', env('SHIPPING_ORIGIN_COUNTRY_CODE', 'MY')),
            'state' => env('SHIPPING_ORIGIN_STATE'),
            'city' => env('SHIPPING_ORIGIN_CITY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */
    'features' => [
        'owner' => [
            'enabled' => env('SHIPPING_OWNER_ENABLED', false),
            'include_global' => env('SHIPPING_OWNER_INCLUDE_GLOBAL', false),
            'auto_assign_on_create' => env('SHIPPING_OWNER_AUTO_ASSIGN_ON_CREATE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'default' => env('SHIPPING_DRIVER', 'manual'),

        'manual' => [
            'driver' => 'manual',
            'name' => 'Manual Shipping',
            'default_rate' => 1000, // RM10.00 in cents
            'estimated_days' => 3,
            'free_shipping_threshold' => null,
        ],

        'flat_rate' => [
            'driver' => 'flat_rate',
            'name' => 'Flat Rate Shipping',
            'rates' => [
                'standard' => [
                    'name' => 'Standard Delivery',
                    'rate' => 800, // RM8.00
                    'estimated_days' => 3,
                ],
                'express' => [
                    'name' => 'Express Delivery',
                    'rate' => 1500, // RM15.00
                    'estimated_days' => 1,
                ],
            ],
        ],

        'zone' => [
            'driver' => 'zone',
            'name' => 'Zone-Based Shipping',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Shopping
    |--------------------------------------------------------------------------
    */
    'rate_shopping' => [
        'strategy' => 'cheapest', // cheapest, fastest, preferred
        'cache_ttl' => 300, // seconds
        'fallback_to_manual' => true,
        'carrier_priority' => [
            // 'jnt' => 1,
            // 'poslaju' => 2,
            // 'gdex' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Free Shipping
    |--------------------------------------------------------------------------
    */
    'free_shipping' => [
        'enabled' => false,
        'threshold' => 15000, // RM150.00 in cents
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */
    'tracking' => [
        'sync_interval' => 3600, // 1 hour in seconds
        'max_tracking_age' => 30, // days to keep syncing
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => env('SHIPPING_API_TIMEOUT', 30),
        'retries' => env('SHIPPING_API_RETRIES', 3),
        'base_delay_ms' => env('SHIPPING_API_BASE_DELAY_MS', 100),
    ],
];
