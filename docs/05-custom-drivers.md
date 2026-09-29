---
title: Creating Custom Drivers
---

# Creating Custom Drivers

This guide covers implementing custom shipping carrier integrations.

## Driver Interface

All drivers must implement `ShippingDriverInterface`:

```php
<?php

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShippingMethodData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Enums\DriverCapability;
use Illuminate\Support\Collection;

interface ShippingDriverInterface
{
    /**
     * Get unique carrier identifier.
     */
    public function getCarrierCode(): string;

    /**
     * Get human-readable carrier name.
     */
    public function getCarrierName(): string;

    /**
     * Check if carrier supports a specific capability.
     */
    public function supports(DriverCapability $capability): bool;

    /**
     * Get available shipping methods for this carrier.
     *
     * @return Collection<int, ShippingMethodData>
     */
    public function getAvailableMethods(): Collection;

    /**
     * Get rate quotes for a shipment.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection;

    /**
     * Create a shipment with the carrier.
     */
    public function createShipment(ShipmentData $data): CarrierOperationResult;

    /**
     * Cancel a shipment. Return a CarrierOperationResult, not a bool.
     */
    public function cancelShipment(string $trackingNumber): CarrierOperationResult;

    /**
     * Generate shipping label. Non-nullable, and keyed on the tracking number.
     *
     * @param  array<string, mixed>  $options
     */
    public function generateLabel(string $trackingNumber, array $options = []): LabelData;

    /**
     * Track a shipment. Non-nullable — return an unknown/last-known state instead of null.
     */
    public function track(string $trackingNumber): TrackingData;

    /**
     * Validate an address.
     */
    public function validateAddress(AddressData $address): AddressValidationResult;

    /**
     * Check if driver services a destination.
     */
    public function servicesDestination(AddressData $destination): bool;
}
```

> **warning**
> There is no `getName()` and no `getCapabilities()`. Use `getCarrierName()`, and expose
> capabilities through `supports(DriverCapability)` — the 12 `DriverCapability` cases are
> `RateQuotes`, `LabelGeneration`, `Tracking`, `Webhooks`, `Returns`, `AddressValidation`,
> `PickupScheduling`, `CashOnDelivery`, `BatchOperations`, `InsuranceClaims`,
> `MultiPackage`, `InternationalShipping`.
>
> `AddressValidationResult` lives in `AIArmada\Shipping\Contracts`, not `...\Data`.

## Example: J&T Express Driver

```php
<?php

declare(strict_types=1);

namespace App\Shipping\Drivers;

use AIArmada\Shipping\Contracts\AddressValidationResult;
use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShippingMethodData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Enums\DriverCapability;
use AIArmada\Shipping\Enums\TrackingStatus;
use AIArmada\Shipping\Services\RetryService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class JntShippingDriver implements ShippingDriverInterface
{
    protected PendingRequest $client;

    /** @var array<int, DriverCapability> */
    protected array $capabilities;

    public function __construct(
        protected readonly array $config
    ) {
        $this->client = Http::baseUrl($this->config['base_url'])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->config['timeout'] ?? 30);

        $this->capabilities = [
            DriverCapability::RateQuotes,
            DriverCapability::LabelGeneration,
            DriverCapability::Tracking,
            DriverCapability::AddressValidation,
        ];

        // RetryService takes no constructor arguments — it reads
        // shipping.http.retries and shipping.http.base_delay_ms.
        $this->retry = new RetryService();
    }

    public function getCarrierCode(): string
    {
        return 'jnt';
    }

    public function getCarrierName(): string
    {
        return $this->config['name'] ?? 'J&T Express';
    }

    public function supports(DriverCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function getAvailableMethods(): Collection
    {
        return collect([
            new ShippingMethodData(code: 'jnt_standard', name: 'J&T Standard', maxDays: 5),
            new ShippingMethodData(code: 'jnt_express', name: 'J&T Express', maxDays: 2),
        ]);
    }

    public function servicesDestination(AddressData $destination): bool
    {
        // J&T only services Malaysia and selected SEA countries
        return in_array($destination->country, ['MY', 'SG', 'ID', 'TH', 'PH', 'VN']);
    }

    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection {
        // PackageData::$weight is already an int in grams — sum integers,
        // never scale a float.
        $totalWeight = collect($packages)->sum(fn (PackageData $p) => $p->weight);

        $quotes = $this->retry->execute(function () use ($origin, $destination, $totalWeight): array {
            $response = $this->client->post('/rates', [
                'origin' => [
                    'postcode' => $origin->postcode,
                    'country' => $origin->country,
                ],
                'destination' => [
                    'postcode' => $destination->postcode,
                    'country' => $destination->country,
                ],
                // The carrier returns major units as a decimal string; convert
                // with string math, not (int) ($x * 100).
                'weight' => $totalWeight,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to fetch J&T rates');
            }

            return $response->json('rates') ?? [];
        });

        return collect($quotes)->map(fn (array $rate) => new RateQuoteData(
            carrier: 'jnt',
            service: (string) $rate['service_code'],
            rate: (int) round(((float) $rate['amount']) * 100),
            currency: 'MYR',
            estimatedDays: (int) ($rate['days_min'] ?? $rate['days_max'] ?? 0),
        ));
    }

    public function createShipment(ShipmentData $shipment): CarrierOperationResult
    {
        /** @var array<string, mixed> $data */
        $data = $this->retry->execute(function () use ($shipment): array {
            $response = $this->client->post('/shipments', [
                'reference' => $shipment->reference,
                'sender' => $this->formatAddress($shipment->origin),
                'receiver' => $this->formatAddress($shipment->destination),
                'service' => $shipment->serviceCode,
                // getTotalWeight() is a method, not a property
                'weight' => $shipment->getTotalWeight(),
                'items' => collect($shipment->items)->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                ])->all(),
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to create J&T shipment: ' . $response->body());
            }

            return $response->json();
        });

        return CarrierOperationResult::succeeded(
            trackingNumber: $data['tracking_number'] ?? null,
            carrierReference: $data['order_id'] ?? null,
        );
    }

    public function cancelShipment(string $trackingNumber): CarrierOperationResult
    {
        try {
            $response = $this->retry->execute(fn () => $this->client->delete("/shipments/{$trackingNumber}"));

            return $response->successful()
                ? CarrierOperationResult::succeeded(trackingNumber: $trackingNumber)
                : CarrierOperationResult::failed('J&T rejected the cancellation');
        } catch (\Throwable $e) {
            return CarrierOperationResult::failed($e->getMessage(), retryable: true);
        }
    }

    public function generateLabel(string $trackingNumber, array $options = []): LabelData
    {
        $response = $this->retry->execute(fn () => $this->client->get("/labels/{$trackingNumber}"));

        return new LabelData(
            format: 'pdf',
            content: base64_encode($response->body()),
            trackingNumber: $trackingNumber,
        );
    }

    public function track(string $trackingNumber): TrackingData
    {
        $response = $this->retry->execute(fn () => $this->client->get("/track/{$trackingNumber}"));

        if ($response->failed()) {
            // Non-nullable return — report an unknown state rather than null
            return new TrackingData(
                trackingNumber: $trackingNumber,
                status: TrackingStatus::LabelCreated,
                events: collect(),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return new TrackingData(
            trackingNumber: $trackingNumber,
            status: $this->mapStatus((string) $data['status']),
            events: collect($data['events'] ?? [])->map(fn (array $event) => new TrackingEventData(
                code: (string) ($event['code'] ?? $event['status']),
                description: (string) $event['description'],
                timestamp: CarbonImmutable::parse($event['timestamp']),
                normalizedStatus: $this->mapStatus((string) $event['status']),
                location: $event['location'] ?? null,
            )),
            carrier: 'jnt',
        );
    }

    public function validateAddress(AddressData $address): AddressValidationResult
    {
        // J&T doesn't provide address validation.
        // Ctor: (bool $valid, ?AddressData $correctedAddress = null, array $warnings = [], array $errors = [])
        return new AddressValidationResult(valid: true);
    }

    protected function formatAddress(AddressData $address): array
    {
        return [
            'name' => $address->name,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country' => $address->country,
        ];
    }

    protected function mapStatus(string $carrierStatus): TrackingStatus
    {
        return match ($carrierStatus) {
            'CREATED', 'PENDING' => TrackingStatus::LabelCreated,
            'AWAITING_PICKUP' => TrackingStatus::AwaitingPickup,
            'PICKED_UP' => TrackingStatus::PickedUp,
            'IN_TRANSIT' => TrackingStatus::InTransit,
            'AT_FACILITY' => TrackingStatus::ArrivedAtFacility,
            'DEPARTED_FACILITY' => TrackingStatus::DepartedFacility,
            'IN_CUSTOMS' => TrackingStatus::InCustoms,
            'CUSTOMS_CLEARED' => TrackingStatus::CustomsCleared,
            'OUT_FOR_DELIVERY' => TrackingStatus::OutForDelivery,
            'DELIVERED' => TrackingStatus::Delivered,
            'SIGNED_FOR' => TrackingStatus::SignedFor,
            'DELIVERY_FAILED' => TrackingStatus::DeliveryAttemptFailed,
            'ADDRESS_ISSUE' => TrackingStatus::AddressIssue,
            'REFUSED' => TrackingStatus::CustomerRefused,
            'DAMAGED' => TrackingStatus::Damaged,
            'LOST' => TrackingStatus::Lost,
            'DELAYED' => TrackingStatus::Delayed,
            'ON_HOLD' => TrackingStatus::OnHold,
            'RETURNED' => TrackingStatus::ReturnToSender,
            default => TrackingStatus::LabelCreated,
        };
    }
}
```

> **warning**
> `TrackingStatus` has 24 cases. There is **no** `Pending`, `Exception`, `ReturnedToSender`,
> or `Unknown` case — the return statuses are `ReturnToSender`, `ReturnInTransit`, and
> `ReturnDelivered`, and exceptions are `DeliveryAttemptFailed`, `AddressIssue`,
> `CustomerRefused`, `Damaged`, `Lost`, `Delayed`, `OnHold`. A `match` needs an explicit
> default arm.

## Registering the Driver

In your service provider:

```php
<?php

namespace App\Providers;

use AIArmada\Shipping\Facades\Shipping;
use App\Shipping\Drivers\JntShippingDriver;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Shipping::extend('jnt', function ($container) {
            return new JntShippingDriver(
                config('shipping.drivers.jnt', [])
            );
        });
    }
}
```

## Configuration

Add to `config/shipping.php`:

```php
'drivers' => [
    'jnt' => [
        'name' => 'J&T Express',
        'base_url' => env('JNT_API_URL', 'https://api.jtexpress.my'),
        'api_key' => env('JNT_API_KEY'),
        'timeout' => 30,
        'retries' => 3,
    ],
],
```

## Status Mappers

For complex status mapping, implement `StatusMapperInterface`:

```php
<?php

namespace App\Shipping\Mappers;

use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Enums\TrackingStatus;

class JntStatusMapper implements StatusMapperInterface
{
    public function getCarrierCode(): string
    {
        return 'jnt';
    }

    // One required parameter — there is no $eventDescription.
    public function map(string $carrierEventCode): TrackingStatus
    {
        return match ($carrierEventCode) {
            '101', '102' => TrackingStatus::LabelCreated,
            '103' => TrackingStatus::AwaitingPickup,
            '201' => TrackingStatus::PickedUp,
            '301', '302', '303' => TrackingStatus::InTransit,
            '401' => TrackingStatus::OutForDelivery,
            '501' => TrackingStatus::Delivered,
            // DeliveryFailed does not exist; DeliveryAttemptFailed is the case name
            '601', '602' => TrackingStatus::DeliveryAttemptFailed,
            '701' => TrackingStatus::ReturnToSender,
            default => TrackingStatus::LabelCreated,
        };
    }
}
```

Register the mapper:

```php
Shipping::registerStatusMapper(new JntStatusMapper());
```

## Free Shipping Policy Interface

Implement `FreeShippingPolicyInterface` to define custom free-shipping eligibility rules.
Register implementations via `FreeShippingPolicyRegistry`.

```php
<?php

namespace App\Shipping\Policies;

use AIArmada\Shipping\Contracts\FreeShippingPolicyInterface;
use AIArmada\Shipping\Services\FreeShippingResult;

class MemberFreeShippingPolicy implements FreeShippingPolicyInterface
{
    public function key(): string
    {
        return 'member';
    }

    public function evaluate(int | object $subtotal, array $context = []): ?FreeShippingResult
    {
        $isMember = $context['is_member'] ?? false;

        if (! $isMember) {
            return null;
        }

        // Members get free shipping on orders above RM50 — 5000 minor units
        $threshold = 5000;

        if ($subtotal >= $threshold) {
            return new FreeShippingResult(
                applies: true,
                message: 'Free shipping for members!',
            );
        }

        return new FreeShippingResult(
            applies: false,
            nearThreshold: true,
            remainingAmount: $threshold - $subtotal,
            message: 'Add more for free shipping!',
        );
    }
}
```
Register in a service provider:

```php
use AIArmada\Shipping\Support\FreeShippingPolicyRegistry;

$registry = app(FreeShippingPolicyRegistry::class);
$registry->register(new MemberFreeShippingPolicy());
```

## Zone Resolution Strategy Interface

Implement `ZoneResolutionStrategyInterface` to define custom zone matching logic.
Register implementations via `ZoneResolutionStrategyRegistry`.

```php
<?php

namespace App\Shipping\Strategies;

use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Models\ShippingZone;
use Illuminate\Support\Collection;

class B2BZoneResolutionStrategy implements ZoneResolutionStrategyInterface
{
    public function key(): string
    {
        return 'b2b';
    }

    public function resolve(AddressData $address, Collection $candidates): Collection
    {
        // ShippingZone has no `metadata` column. Match on `type`
        // (country|state|postcode|radius) plus the active flag, then let
        // the model do the address comparison.
        return $candidates
            ->filter(fn (ShippingZone $zone) => $zone->active
                && $zone->type === 'postcode'
                && $zone->matchesAddress($address))
            ->sortByDesc(fn (ShippingZone $zone) => $zone->priority);
    }
}
```

Register in a service provider:

```php
use AIArmada\Shipping\Support\ZoneResolutionStrategyRegistry;

$registry = app(ZoneResolutionStrategyRegistry::class);
$registry->register(new B2BZoneResolutionStrategy());
```

The `ZoneResolutionStrategyRegistry` is consumed by `ShippingZoneResolver` to find matching
zones. The default resolver uses the `geo` strategy; you can override which key to use via
configuration or by extending the resolver.

## Testing Your Driver

```php
<?php

use App\Shipping\Drivers\JntShippingDriver;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;

test('jnt driver returns rates for malaysian address', function () {
    $driver = new JntShippingDriver([
        'base_url' => 'https://api.jtexpress.my',
        'api_key' => 'test-key',
    ]);

    // AddressData requires name, phone, line1, and postcode — country defaults to 'MY'
    $origin = AddressData::from([
        'name' => 'Warehouse',
        'phone' => '0123456789',
        'line1' => 'Lot 1',
        'postcode' => '43000',
    ]);

    $destination = AddressData::from([
        'name' => 'Siti',
        'phone' => '0123456789',
        'line1' => 'Lot 2',
        'postcode' => '47800',
        'country' => 'MY',
    ]);

    $packages = [
        PackageData::from(['weight' => 500]),
    ];

    // getRates() takes origin, destination, packages, options
    $rates = $driver->getRates($origin, $destination, $packages);

    expect($rates)->not->toBeEmpty();
    expect($rates->first()->carrier)->toBe('jnt');
});
```
