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
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentResultData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Enums\DriverCapability;
use Illuminate\Support\Collection;

interface ShippingDriverInterface
{
    /**
     * Get the unique carrier code.
     */
    public function getCarrierCode(): string;

    /**
     * Get display name for UI.
     */
    public function getName(): string;

    /**
     * Get driver capabilities.
     * @return array<DriverCapability>
     */
    public function getCapabilities(): array;

    /**
     * Check if driver supports a capability.
     */
    public function supports(DriverCapability $capability): bool;

    /**
     * Check if driver services a destination.
     */
    public function servicesDestination(AddressData $destination): bool;

    /**
     * Get rate quotes.
     * @param PackageData[] $packages
     * @return Collection<int, RateQuoteData>
     */
    public function getRates(AddressData $destination, array $packages): Collection;

    /**
     * Create a shipment with the carrier.
     */
    public function createShipment(ShipmentData $shipment): ShipmentResultData;

    /**
     * Track a shipment.
     */
    public function track(string $trackingNumber): ?TrackingData;

    /**
     * Generate shipping label.
     */
    public function generateLabel(ShipmentData $shipment): ?LabelData;

    /**
     * Cancel a shipment.
     */
    public function cancel(string $trackingNumber): bool;

    /**
     * Validate an address.
     */
    public function validateAddress(AddressData $address): AddressValidationResult;
}
```

## Example: J&T Express Driver

```php
<?php

declare(strict_types=1);

namespace App\Shipping\Drivers;

use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentResultData;
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
    protected RetryService $retry;

    public function __construct(
        protected readonly array $config
    ) {
        $this->client = Http::baseUrl($this->config['base_url'])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->config['timeout'] ?? 30);

        $this->retry = new RetryService(
            maxAttempts: $this->config['retries'] ?? 3
        );
    }

    public function getCarrierCode(): string
    {
        return 'jnt';
    }

    public function getName(): string
    {
        return $this->config['name'] ?? 'J&T Express';
    }

    public function getCapabilities(): array
    {
        return [
            DriverCapability::RateQuotes,
            DriverCapability::LabelGeneration,
            DriverCapability::Tracking,
            DriverCapability::Cancellation,
        ];
    }

    public function supports(DriverCapability $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    public function servicesDestination(AddressData $destination): bool
    {
        // J&T only services Malaysia and selected SEA countries
        return in_array($destination->country, ['MY', 'SG', 'ID', 'TH', 'PH', 'VN']);
    }

    public function getRates(AddressData $destination, array $packages): Collection
    {
        $totalWeight = collect($packages)->sum(fn (PackageData $p) => $p->weight);

        return $this->retry->execute(function () use ($destination, $totalWeight): Collection {
            $response = $this->client->post('/rates', [
                'destination' => [
                    'postcode' => $destination->postcode,
                    'country' => $destination->country,
                ],
                'weight' => $totalWeight,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to fetch J&T rates');
            }

            return collect($response->json('rates'))->map(fn (array $rate) => RateQuoteData::from([
                'carrier_code' => 'jnt',
                'service_code' => $rate['service_code'],
                'service_name' => $rate['service_name'],
                'amount' => (int) ($rate['amount'] * 100), // Convert to cents
                'currency' => 'MYR',
                'estimated_days_min' => $rate['days_min'],
                'estimated_days_max' => $rate['days_max'],
            ]));
        });
    }

    public function createShipment(ShipmentData $shipment): ShipmentResultData
    {
        return $this->retry->execute(function () use ($shipment): ShipmentResultData {
            $response = $this->client->post('/shipments', [
                'sender' => $this->formatAddress($shipment->origin),
                'receiver' => $this->formatAddress($shipment->destination),
                'service' => $shipment->serviceCode,
                'weight' => $shipment->totalWeight,
                'items' => collect($shipment->items)->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                ])->toArray(),
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to create J&T shipment: ' . $response->body());
            }

            $data = $response->json();

            return ShipmentResultData::from([
                'success' => true,
                'carrier_code' => 'jnt',
                'tracking_number' => $data['tracking_number'],
                'label_url' => $data['label_url'] ?? null,
            ]);
        });
    }

    public function track(string $trackingNumber): ?TrackingData
    {
        return $this->retry->execute(function () use ($trackingNumber): ?TrackingData {
            $response = $this->client->get("/track/{$trackingNumber}");

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return TrackingData::from([
                'tracking_number' => $trackingNumber,
                'carrier_code' => 'jnt',
                'status' => $this->mapStatus($data['status']),
                'estimated_delivery' => $data['estimated_delivery'] ?? null,
                'events' => collect($data['events'])->map(fn (array $event) => TrackingEventData::from([
                    'timestamp' => $event['timestamp'],
                    'status' => $this->mapStatus($event['status']),
                    'description' => $event['description'],
                    'location' => $event['location'] ?? null,
                ]))->toArray(),
            ]);
        });
    }

    public function generateLabel(ShipmentData $shipment): ?LabelData
    {
        return $this->retry->execute(function () use ($shipment): ?LabelData {
            $response = $this->client->get("/labels/{$shipment->trackingNumber}");

            if ($response->failed()) {
                return null;
            }

            return LabelData::from([
                'format' => 'pdf',
                'content_base64' => base64_encode($response->body()),
                'tracking_number' => $shipment->trackingNumber,
            ]);
        });
    }

    public function cancel(string $trackingNumber): bool
    {
        return $this->retry->execute(function () use ($trackingNumber): bool {
            $response = $this->client->delete("/shipments/{$trackingNumber}");

            return $response->successful();
        });
    }

    public function validateAddress(AddressData $address): AddressValidationResult
    {
        // J&T doesn't provide address validation
        return new AddressValidationResult(
            valid: true,
            address: $address,
        );
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
            'CREATED', 'PENDING' => TrackingStatus::Pending,
            'PICKED_UP' => TrackingStatus::PickedUp,
            'IN_TRANSIT' => TrackingStatus::InTransit,
            'OUT_FOR_DELIVERY' => TrackingStatus::OutForDelivery,
            'DELIVERED' => TrackingStatus::Delivered,
            'EXCEPTION' => TrackingStatus::Exception,
            'RETURNED' => TrackingStatus::ReturnedToSender,
            default => TrackingStatus::Unknown,
        };
    }
}
```

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

    public function map(string $eventCode, ?string $eventDescription = null): TrackingStatus
    {
        return match ($eventCode) {
            '101', '102' => TrackingStatus::Pending,
            '201' => TrackingStatus::PickedUp,
            '301', '302', '303' => TrackingStatus::InTransit,
            '401' => TrackingStatus::OutForDelivery,
            '501' => TrackingStatus::Delivered,
            '601', '602' => TrackingStatus::DeliveryFailed,
            '701' => TrackingStatus::ReturnedToSender,
            default => TrackingStatus::Unknown,
        };
    }
}
```

Register the mapper:

```php
Shipping::registerStatusMapper(new JntStatusMapper());
```

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

    $destination = AddressData::from([
        'postcode' => '47800',
        'country' => 'MY',
    ]);

    $packages = [
        PackageData::from(['weight' => 500]),
    ];

    $rates = $driver->getRates($destination, $packages);

    expect($rates)->not->toBeEmpty();
    expect($rates->first()->carrierCode)->toBe('jnt');
});
```
