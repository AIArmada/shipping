---
title: Overview
---

# Shipping Package Overview

## Purpose

The `aiarmada/shipping` package provides the carrier-agnostic shipping and fulfilment abstraction for the Commerce ecosystem.

## What this package owns

- Shipping manager, driver contracts, and driver resolution
- Shipments, shipment items, shipment events, labels, shipping zones, shipping rates, and return authorizations
- Rate shopping, status normalization, retry logic, and shipping strategy selection
- Owner-aware shipment and shipping-configuration flows

## What this package does not own

- Checkout orchestration or order persistence
- Carrier-specific integrations such as J&T; those belong to dedicated adapter packages like `aiarmada/jnt`
- Filament admin surfaces; those belong to `aiarmada/filament-shipping`

## Related packages

- [`aiarmada/filament-shipping`](../../filament-shipping/docs/01-overview.md) — Filament admin resources and dashboards for shipping
- [`aiarmada/orders`](../../orders/docs/01-overview.md) — order fulfilment integration
- [`aiarmada/cart`](../../cart/docs/01-overview.md) — optional cart subtotal and free-shipping evaluation
- [`aiarmada/jnt`](../../jnt/docs/01-overview.md) — carrier adapter built on top of shipping abstractions
- [`aiarmada/commerce-support`](../../commerce-support/docs/01-overview.md) — owner scoping and shared infrastructure

## Main models services or surfaces

- **Models** — shipment, shipment item, shipment event, shipment label, shipping zone, shipping rate, return authorization, return authorization item
- **Actions** — `CreateShipment`, `ShipShipment`, `CancelShipment`, `GenerateLabel`, `RecordTrackingEvent`
- **Contracts** — `ShippingDriverInterface`, `FreeShippingPolicyInterface`, `ZoneResolutionStrategyInterface`, `RateSelectionStrategyInterface`, `StatusMapperInterface`
- **Strategies** — `GeoZoneResolutionStrategy`, `ThresholdFreeShippingPolicy`, plus rate selection strategies (`CheapestRateStrategy`, `FastestRateStrategy`, `BalancedRateStrategy`, `PreferredCarrierStrategy`)
- **Core surfaces** — `ShippingManager`, driver interface, rate shopping, label generation, tracking, returns, and strategy selection
- **Handlers and services** — orders integration, rate caching, and fulfilment helpers
- **Registries** — `ZoneResolutionStrategyRegistry`, `FreeShippingPolicyRegistry`
- **Filament support** — `ShippingStatsAggregator` (in `aiarmada/filament-shipping`)

## Owner scoping and security notes

- Shipping records are owner-aware and should follow the `commerce-support` owner-boundary rules
- Rate, zone, and shipment identifiers should be resolved inside the current owner scope before write operations
- Carrier adapters should preserve the same owner-safe semantics on non-UI entry points like commands, jobs, and webhooks

The `aiarmada/shipping` package provides a multi-carrier shipping abstraction layer for Laravel commerce applications. It follows Laravel's Manager pattern to provide a unified interface for multiple shipping carriers while supporting complex features like rate shopping, tracking aggregation, and returns management.

## Key Features

### Multi-Carrier Support
- **Unified Interface**: Single API for all shipping carriers via `ShippingDriverInterface`
- **Driver Architecture**: Easy extension with custom carrier implementations
- **Built-in Drivers**: Manual fulfillment and flat-rate shipping included
- **Status Mapping**: Normalize carrier-specific tracking statuses to standard statuses

### Rate Shopping Engine
- **Concurrent Fetching**: Fetch rates from multiple carriers in parallel using Laravel's Concurrency facade
- **Caching**: Cache rate quotes to reduce API calls
- **Selection Strategies**: Choose cheapest, fastest, preferred carrier, or balanced rates
- **Fallback Support**: Automatic fallback to default driver on errors

### Shipment Lifecycle
- **State Machine**: Comprehensive status workflow from Draft → Delivered
- **Event Tracking**: Complete history of all shipment events
- **Label Generation**: Support for multiple labels per shipment
- **Retry Logic**: Automatic retries with exponential backoff for carrier API calls

### Returns Management
- **RMA Workflow**: Full Return Merchandise Authorization support
- **Auto-Generated RMA Numbers**: Unique identifiers for return tracking
- **Return Items**: Track individual items within a return

### Shipping Zones & Rates
- **Geographic Zones**: Match addresses by country, state, postcode, or radius
- **Multiple Rate Types**: Flat rate, per-kg, per-item, percentage, or table-based
- **Free Shipping**: Threshold-based free shipping evaluation

### Multi-Tenancy
- **Owner Scoping**: Full support for tenant isolation via commerce-support
- **Configurable**: Enable/disable per deployment

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    ShippingManager                          │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────────┐    │
│  │ Manual  │  │FlatRate │  │ Custom  │  │  ...more    │    │
│  │ Driver  │  │ Driver  │  │ Driver  │  │  drivers    │    │
│  └────┬────┘  └────┬────┘  └────┬────┘  └──────┬──────┘    │
└───────┼────────────┼───────────┼───────────────┼───────────┘
        │            │           │               │
        └────────────┴───────────┴───────────────┘
                            │
              ┌─────────────┴─────────────┐
              │   ShippingDriverInterface  │
              │   - getRates()             │
              │   - createShipment()       │
              │   - track()                │
              │   - generateLabel()        │
              │   - cancel()               │
              └───────────────────────────┘
```

## Package Structure

```
packages/shipping/
├── config/
│   └── shipping.php          # Package configuration
├── database/
│   ├── factories/             # Model factories for testing
│   └── migrations/            # Database migrations
├── src/
│   ├── Actions/               # Action classes (CreateShipment, ShipShipment, etc.)
│   ├── Contracts/             # Interfaces and contracts
│   ├── Data/                  # Spatie Laravel Data DTOs
│   ├── Drivers/               # Shipping driver implementations
│   ├── Enums/                 # Status and type enumerations
│   ├── Events/                # Domain events
│   ├── Exceptions/            # Custom exceptions
│   ├── Handlers/              # Integration handlers (Orders)
│   ├── Models/                # Eloquent models
│   ├── Policies/              # Authorization policies
│   ├── Services/              # Core business logic
│   ├── States/                # State machine classes
│   ├── Strategies/            # Rate selection, zone resolution, free shipping strategies
│   ├── Support/               # Helper classes, registries, owner scope
│   ├── ShippingManager.php    # Main manager class
│   ├── Facades/               # Facade class
│   └── ShippingServiceProvider.php
└── docs/
    ├── 01-overview.md         # Package overview
    ├── 02-installation.md     # Installation and setup
    ├── 03-configuration.md    # Configuration reference
    ├── 04-usage.md            # Core usage patterns
    ├── 05-custom-drivers.md   # Custom drivers and extensions
    └── 99-troubleshooting.md  # Troubleshooting guide
```

## Models

| Model | Purpose |
|-------|---------|
| `Shipment` | Main shipment entity with lifecycle management |
| `ShipmentItem` | Individual items within a shipment |
| `ShipmentEvent` | Tracking events and status history |
| `ShipmentLabel` | Generated shipping labels (PDF/ZPL) |
| `ShippingZone` | Geographic zones for rate calculation |
| `ShippingRate` | Zone-based shipping rates |
| `ReturnAuthorization` | RMA for returns processing |
| `ReturnAuthorizationItem` | Items within a return |

## Requirements

- PHP 8.4+
- Laravel 11+
- `aiarmada/commerce-support` package
- `brick/money` for currency handling
- `spatie/laravel-data` for DTOs

## Related Packages

- **filament-shipping**: Filament admin panel integration
- **orders**: Order integration for fulfillment workflows
- **cart**: Cart integration for free shipping evaluation

## Read next

- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Usage](04-usage.md)
- [Custom drivers](05-custom-drivers.md)
- [Multitenancy](06-multitenancy.md)
- [Troubleshooting](99-troubleshooting.md)
- [Filament Shipping overview](../../filament-shipping/docs/01-overview.md)
