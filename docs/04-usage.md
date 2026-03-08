---
title: Usage
---

# Basic Usage

## Using the Shipping Manager

Access shipping functionality via the facade or container:

```php
use AIArmada\Shipping\Facades\Shipping;

// Or via container
$shipping = app('shipping');
```

## Cart Condition Provider

When `aiarmada/cart` is installed, the shipping package registers a condition provider that can
add shipping conditions based on cart metadata. Set the shipping address and (optionally) a
selected method on the cart, then read totals/conditions.

```php
use AIArmada\Cart\Facades\Cart;

Cart::setMetadata('shipping_address', [
    'name' => 'John Doe',
    'line1' => '456 Customer Ave',
    'city' => 'Petaling Jaya',
    'state' => 'Selangor',
    'postcode' => '47800',
    'country' => 'MY',
]);

Cart::setMetadata('selected_shipping_method', [
    'carrier' => 'manual',
    'service' => 'standard',
]);

$total = Cart::total();
```

### Getting Rates

```php
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;

$destination = AddressData::from([
    'line1' => '456 Customer Ave',
    'city' => 'Petaling Jaya',
    'state' => 'Selangor',
    'postcode' => '47800',
    'country' => 'MY',
]);

$packages = [
    PackageData::from([
        'weight' => 500, // grams
        'length' => 20,  // cm
        'width' => 15,
        'height' => 10,
    ]),
];

// Get rates from default driver
$rates = Shipping::getRates($destination, $packages);

// Get rates from specific driver
$rates = Shipping::driver('flat_rate')->getRates($destination, $packages);
```

### Rate Shopping (Best Rate)

```php
use AIArmada\Shipping\Services\RateShoppingEngine;

$engine = app(RateShoppingEngine::class);

// Get best rate across all carriers
$bestRate = $engine->getBestRate($destination, $packages);

// Get all rates from all carriers
$allRates = $engine->getAllRates($destination, $packages);
```

## Creating Shipments

### Using ShipmentService

```php
use AIArmada\Shipping\Services\ShipmentService;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\ShipmentItemData;

$service = app(ShipmentService::class);

$shipmentData = ShipmentData::from([
    'carrier_code' => 'manual',
    'service_code' => 'standard',
    'origin' => AddressData::from([
        'name' => 'My Warehouse',
        'line1' => '123 Warehouse St',
        'city' => 'Kuala Lumpur',
        'state' => 'Kuala Lumpur',
        'postcode' => '50000',
        'country' => 'MY',
    ]),
    'destination' => AddressData::from([
        'name' => 'John Doe',
        'line1' => '456 Customer Ave',
        'city' => 'Petaling Jaya',
        'state' => 'Selangor',
        'postcode' => '47800',
        'country' => 'MY',
        'phone' => '+60123456789',
    ]),
    'items' => [
        ShipmentItemData::from([
            'name' => 'Product A',
            'sku' => 'PROD-A',
            'quantity' => 2,
            'weight' => 250, // grams
        ]),
    ],
    'total_weight' => 500,
]);

// Create shipment (Draft status)
$shipment = $service->create($shipmentData);

// Ship the shipment (transitions to Shipped)
$result = $service->ship($shipment, 'jnt');

// The result includes tracking info
echo $result->trackingNumber; // "JT1234567890"
echo $result->labelUrl;       // URL to label PDF
```

### Creating Shipment for an Order

```php
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Orders\Models\Order;

$order = Order::find($orderId);

$shipment = Shipment::create([
    'shippable_type' => $order->getMorphClass(),
    'shippable_id' => $order->id,
    'carrier_code' => 'jnt',
    'service_code' => 'express',
    'origin' => [...],
    'destination' => [...],
    'status' => ShipmentStatus::Draft,
    'total_weight' => 1500,
]);
```

## Tracking Shipments

### Single Shipment

```php
use AIArmada\Shipping\Facades\Shipping;

$trackingData = Shipping::driver('jnt')->track('JT1234567890');

foreach ($trackingData->events as $event) {
    echo $event->timestamp->format('Y-m-d H:i');
    echo $event->description;
    echo $event->location;
}
```

### Bulk Tracking Sync

```php
use AIArmada\Shipping\Services\TrackingAggregator;

$aggregator = app(TrackingAggregator::class);

// Sync all active shipments
$results = $aggregator->syncAll();

// Sync specific shipments
$shipments = Shipment::whereIn('id', $ids)->get();
$results = $aggregator->sync($shipments);
```

## Generating Labels

```php
use AIArmada\Shipping\Services\ShipmentService;

$service = app(ShipmentService::class);

// Generate label for existing shipment
$label = $service->generateLabel($shipment);

echo $label->format;        // 'pdf' or 'zpl'
echo $label->contentBase64; // Base64-encoded label content

// Save to disk
file_put_contents('label.pdf', base64_decode($label->contentBase64));
```

## Cancelling Shipments

```php
use AIArmada\Shipping\Services\ShipmentService;

$service = app(ShipmentService::class);

// Only Draft and Pending shipments can be cancelled
if ($shipment->isCancellable()) {
    $service->cancel($shipment, 'Customer requested cancellation');
}
```

## Shipping Zones

### Creating Zones

```php
use AIArmada\Shipping\Models\ShippingZone;

// Country-based zone
$zone = ShippingZone::create([
    'name' => 'Malaysia',
    'type' => 'country',
    'countries' => ['MY'],
    'is_active' => true,
]);

// State-based zone
$zone = ShippingZone::create([
    'name' => 'West Malaysia',
    'type' => 'state',
    'countries' => ['MY'],
    'states' => ['Selangor', 'Kuala Lumpur', 'Penang'],
    'is_active' => true,
]);

// Postcode-based zone
$zone = ShippingZone::create([
    'name' => 'Klang Valley',
    'type' => 'postcode',
    'countries' => ['MY'],
    'postcodes' => '40000-48000, 50000-59999, 68000-68100',
    'is_active' => true,
]);
```

### Adding Rates to Zones

```php
use AIArmada\Shipping\Models\ShippingRate;
use AIArmada\Shipping\Enums\RateType;

// Flat rate
$rate = $zone->rates()->create([
    'name' => 'Standard Shipping',
    'carrier_code' => 'manual',
    'service_code' => 'standard',
    'rate_type' => RateType::Flat,
    'base_rate' => 800, // RM8.00
    'min_weight' => 0,
    'max_weight' => 5000, // 5kg
    'delivery_days_min' => 3,
    'delivery_days_max' => 5,
    'is_active' => true,
]);

// Per-kg rate
$rate = $zone->rates()->create([
    'name' => 'Heavy Items',
    'rate_type' => RateType::PerKg,
    'base_rate' => 500,    // RM5.00 base
    'per_kg_rate' => 200,  // RM2.00 per kg
    'min_weight' => 5001,
    'is_active' => true,
]);

// Table-based rate (weight tiers)
$rate = $zone->rates()->create([
    'name' => 'Tiered Shipping',
    'calculation_type' => 'table',
    'base_rate' => 500, // Fallback rate
    'rate_table' => [
        ['min_weight' => 0, 'max_weight' => 500, 'rate' => 500],      // 0-500g: RM5
        ['min_weight' => 501, 'max_weight' => 1000, 'rate' => 800],   // 501-1000g: RM8
        ['min_weight' => 1001, 'max_weight' => 2000, 'rate' => 1200], // 1-2kg: RM12
        ['min_weight' => 2001, 'max_weight' => 5000, 'rate' => 1800], // 2-5kg: RM18
        ['min_weight' => 5001, 'max_weight' => null, 'rate' => 2500], // 5kg+: RM25
    ],
    'is_active' => true,
]);
```

### Resolving Zones for Address

```php
use AIArmada\Shipping\Services\ShippingZoneResolver;
use AIArmada\Shipping\Data\AddressData;

$resolver = app(ShippingZoneResolver::class);

$address = AddressData::from([
    'city' => 'Petaling Jaya',
    'state' => 'Selangor',
    'postcode' => '47800',
    'country' => 'MY',
]);

$zone = $resolver->resolve($address);
$rates = $zone?->rates()->active()->get();
```

## Free Shipping Evaluation

```php
use AIArmada\Shipping\Services\FreeShippingEvaluator;

$evaluator = app(FreeShippingEvaluator::class);

// With cart subtotal in cents
$result = $evaluator->evaluate(12000); // RM120.00

if ($result?->applies) {
    echo "Free shipping!";
} elseif ($result?->nearThreshold) {
    echo $result->message; // "Add RM30.00 more for free shipping!"
}

// Or with a cart-like object
$result = $evaluator->evaluate($cart);
```

## Returns Management

### Creating an RMA

```php
use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\Enums\ReturnReason;

$rma = ReturnAuthorization::create([
    'shipment_id' => $shipment->id,
    'customer_name' => 'John Doe',
    'customer_email' => 'john@example.com',
    'reason' => ReturnReason::Defective,
    'notes' => 'Product arrived damaged',
    'status' => 'pending',
]);

// Add items to return
$rma->items()->create([
    'shipment_item_id' => $shipmentItem->id,
    'quantity' => 1,
    'reason' => ReturnReason::Defective,
]);
```

### Processing Returns

```php
// Approve return
$rma->update([
    'status' => 'approved',
    'approved_at' => now(),
    'approved_by' => auth()->id(),
]);

// Generate return label
$label = Shipping::driver($rma->carrier_code)->generateReturnLabel($rma);
$rma->update(['return_tracking_number' => $label->trackingNumber]);

// Mark as received
$rma->update([
    'status' => 'received',
    'received_at' => now(),
]);

// Complete refund
$rma->update([
    'status' => 'refunded',
    'refunded_at' => now(),
]);
```

## Status Workflow

Shipments follow a state machine workflow:

```
Draft → Pending → Shipped → InTransit → OutForDelivery → Delivered
                    ↓
                Exception → DeliveryFailed → ReturnToSender
                    ↓
                OnHold
                    ↓
                Cancelled
```

Check status capabilities:

```php
$shipment->status->canTransitionTo(ShipmentStatus::Shipped);
$shipment->status->isCancellable();
$shipment->status->isTerminal();
$shipment->status->isDelivered();
```
