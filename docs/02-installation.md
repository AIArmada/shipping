---
title: Installation
---

# Installation

## Requirements

- PHP 8.4 or higher
- Laravel 11 or higher
- `aiarmada/commerce-support` package

## Install via Composer

```bash
composer require aiarmada/shipping
```

The package uses Laravel's auto-discovery, so the service provider will be registered automatically.

## Publish Configuration

```bash
php artisan vendor:publish --tag=shipping-config
```

This will publish the configuration file to `config/shipping.php`.

## Run Migrations

```bash
php artisan migrate
```

This creates the following tables (with configurable prefix, default `shipping_`):

| Table | Purpose |
|-------|---------|
| `shipping_shipments` | Main shipment records |
| `shipping_shipment_items` | Line items within shipments |
| `shipping_shipment_events` | Tracking events history |
| `shipping_shipment_labels` | Generated shipping labels |
| `shipping_zones` | Geographic shipping zones |
| `shipping_rates` | Zone-based shipping rates |
| `shipping_return_authorizations` | RMA records |
| `shipping_return_authorization_items` | Return line items |

## Publish Migrations (Optional)

If you need to customize the migrations:

```bash
php artisan vendor:publish --tag=shipping-migrations
```

## Environment Variables

Add these to your `.env` file:

```env
# Origin address for shipments
SHIPPING_ORIGIN_LINE1="123 Warehouse St"
SHIPPING_ORIGIN_CITY="Kuala Lumpur"
SHIPPING_ORIGIN_STATE="Kuala Lumpur"
SHIPPING_ORIGIN_POSTCODE="50000"
SHIPPING_ORIGIN_COUNTRY="MY"

# Default currency
SHIPPING_CURRENCY=MYR
```

## Optional: Filament Integration

For admin panel integration:

```bash
composer require aiarmada/filament-shipping
```

See the [filament-shipping documentation](../filament-shipping/01-overview.md) for setup.

## Optional: Orders Integration

If using with the orders package, the shipping package will automatically register the `OrderFulfillmentHandler` when the orders package is detected:

```bash
composer require aiarmada/orders
```

## Multi-Tenant Setup

import Aside from "@components/Aside.astro"

<Aside variant="warning">
  Owner scoping is **disabled by default** (`SHIPPING_OWNER_ENABLED=false`). In a multi-tenant deployment every tenant will see all shipments, zones, and returns unless you enable it. Set `SHIPPING_OWNER_ENABLED=true` and bind `OwnerResolverInterface` before going live.
</Aside>

```env
SHIPPING_OWNER_ENABLED=true
```

Bind the resolver in `AppServiceProvider::register()`:

```php
use AIArmada\CommerceSupport\Contracts\OwnerResolverInterface;

$this->app->bind(OwnerResolverInterface::class, CurrentTenantResolver::class);
```

See [Multitenancy](./06-multitenancy.md) for full details.

## Verification

Test the installation by accessing the shipping manager:

```php
use AIArmada\Shipping\Facades\Shipping;

// Get available drivers
$drivers = Shipping::getAvailableDrivers();
// Returns: ['null', 'manual', 'flat_rate']

// Get the default driver
$driver = Shipping::driver();
// Returns: ManualShippingDriver instance
```
