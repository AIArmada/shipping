---
title: Overview
---

# Shipping Package Overview

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
│   ├── Strategies/            # Rate selection strategies
│   ├── Support/               # Helper classes
│   ├── ShippingManager.php    # Main manager class
│   └── ShippingServiceProvider.php
└── docs/
    └── vision/                # Architecture vision documents
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
