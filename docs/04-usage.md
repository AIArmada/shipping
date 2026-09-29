---
title: Usage
---

## Canonical API: Actions

The recommended entrypoint for shipment operations is the Action classes. Each Action uses `lorisleiva/laravel-actions`
and supports both `::run()` (static) and `->handle()` (injected).

```php
use AIArmada\Shipping\Actions\CreateShipment;
use AIArmada\Shipping\Actions\ShipShipment;
use AIArmada\Shipping\Actions\CancelShipment;
use AIArmada\Shipping\Actions\GenerateLabel;
use AIArmada\Shipping\Actions\RecordTrackingEvent;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\TrackingEventData;

// Create a new shipment (Draft status)
$shipment = CreateShipment::run(
    ShipmentData::from([
        'reference' => 'ORD-123',
        'carrierCode' => 'manual',
        'serviceCode' => 'standard',
        'origin' => $origin,
        'destination' => $destination,
        'items' => $items,
    ]),
);

// Ship the shipment (contacts carrier, transitions to Shipped)
$shipment = ShipShipment::run($shipment);

// Cancel a shipment (only Draft/Pending are cancellable)
$shipment = CancelShipment::run($shipment, reason: 'Customer cancellation');

// Generate a label for an existing shipment
$label = GenerateLabel::run($shipment);

// Record a tracking event
$event = RecordTrackingEvent::run(
    $shipment,
    TrackingEventData::from([
        'code' => 'pickup',
        'description' => 'Package picked up',
        'timestamp' => now(),
    ]),
);
```

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
    'phone' => '+60123456789',
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

// AddressData requires name, phone, line1 and postcode.
$origin = AddressData::from([
    'name' => 'My Warehouse',
    'phone' => '+60300000000',
    'line1' => '123 Warehouse St',
    'postcode' => '50000',
    'country' => 'MY',
]);

$destination = AddressData::from([
    'name' => 'John Doe',
    'phone' => '+60123456789',
    'line1' => '456 Customer Ave',
    'postcode' => '47800',
    'country' => 'MY',
    'city' => 'Petaling Jaya',
    'state' => 'Selangor',
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
$rates = Shipping::getRates($origin, $destination, $packages);

// Get rates from specific driver
$rates = Shipping::driver('flat_rate')->getRates($origin, $destination, $packages);
```

### Rate Shopping (Best Rate)

```php
use AIArmada\Shipping\Services\RateShoppingEngine;

$engine = app(RateShoppingEngine::class);

// Get best rate across all carriers
$bestRate = $engine->getBestRate($origin, $destination, $packages);

// Get all rates from all carriers
$allRates = $engine->getAllRates($origin, $destination, $packages);
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
    'reference' => 'ORD-123',
    'carrierCode' => 'manual',
    'serviceCode' => 'standard',
    'origin' => AddressData::from([
        'name' => 'My Warehouse',
        'phone' => '+60300000000',
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
]);

// Create shipment (Draft status)
$shipment = $service->create($shipmentData);

// Ship the shipment (transitions to Shipped)
$shipment = $service->ship($shipment);

// The shipment carries the carrier tracking number
echo $shipment->tracking_number; // "JT1234567890"
```

`ShipmentData` has no `total_weight` property — total weight is derived from the
`items` and `packages` you pass via `getTotalWeight()`.

### Creating Shipment for an Order

```php
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\Draft;
use AIArmada\Orders\Models\Order;

$order = Order::find($orderId);

$shipment = Shipment::create([
    'shippable_type' => $order->getMorphClass(),
    'shippable_id' => $order->id,
    'carrier_code' => 'jnt',
    'service_code' => 'express',
    'origin_address' => $originPayload,
    'destination_address' => $destinationPayload,
    'status' => Draft::class,
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

// Sync a single shipment
$shipment = $aggregator->syncTracking($shipment);

// Sync a batch
$results = $aggregator->syncBatch(
    Shipment::whereIn('id', $ids)->get()
);

// Find shipments due for a sync
$due = $aggregator->getShipmentsNeedingUpdate(limit: 100);
```

## Generating Labels

```php
use AIArmada\Shipping\Services\ShipmentService;

$service = app(ShipmentService::class);

// Generate label for existing shipment
$label = $service->generateLabel($shipment);

echo $label->format;   // 'pdf' or 'zpl'
echo $label->url;      // label URL
echo $label->content;  // raw label content (base64 for binary formats)

// Save to disk
file_put_contents('label.pdf', $label->getDecodedContent());
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
    'code' => 'MY',
    'type' => 'country',
    'countries' => ['MY'],
    'active' => true,
]);

// State-based zone
$zone = ShippingZone::create([
    'name' => 'West Malaysia',
    'code' => 'MY-WEST',
    'type' => 'state',
    'countries' => ['MY'],
    'states' => ['Selangor', 'Kuala Lumpur', 'Penang'],
    'active' => true,
]);

// Postcode-based zone
$zone = ShippingZone::create([
    'name' => 'Klang Valley',
    'code' => 'MY-KLANG-VALLEY',
    'type' => 'postcode',
    'countries' => ['MY'],
    'postcode_ranges' => [['from' => '40000', 'to' => '48000'], ['from' => '50000', 'to' => '59999']],
    'active' => true,
]);
```

> **info**
> `ShippingZone` fillable columns are `postcode_ranges` and `active` — not
> `postcodes` / `is_active`. The migration defaults `active` to `true`.

### Adding Rates to Zones

`ShippingRate` uses `calculation_type` (`flat`, `per_kg`, `per_item`,
`percentage`, `table`), `method_code`, `per_unit_rate`,
`estimated_days_min` / `estimated_days_max`, and `active`:

```php
use AIArmada\Shipping\Models\ShippingRate;

// Flat rate
$rate = $zone->rates()->create([
    'name' => 'Standard Shipping',
    'carrier_code' => 'manual',
    'method_code' => 'standard',
    'calculation_type' => 'flat',
    'base_rate' => 800, // RM8.00
    'estimated_days_min' => 3,
    'estimated_days_max' => 5,
    'active' => true,
]);

// Per-kg rate
$rate = $zone->rates()->create([
    'name' => 'Heavy Items',
    'method_code' => 'heavy',
    'calculation_type' => 'per_kg',
    'base_rate' => 500,     // RM5.00 base (covers the first kg)
    'per_unit_rate' => 200, // RM2.00 per additional kg
    'active' => true,
]);

// Table-based rate (weight tiers)
$rate = $zone->rates()->create([
    'name' => 'Tiered Shipping',
    'method_code' => 'tiered',
    'calculation_type' => 'table',
    'base_rate' => 500, // Fallback rate
    'rate_table' => [
        ['min_weight' => 0, 'max_weight' => 500, 'rate' => 500],      // 0-500g: RM5
        ['min_weight' => 501, 'max_weight' => 1000, 'rate' => 800],   // 501-1000g: RM8
        ['min_weight' => 1001, 'max_weight' => 2000, 'rate' => 1200], // 1-2kg: RM12
        ['min_weight' => 2001, 'max_weight' => 5000, 'rate' => 1800], // 2-5kg: RM18
        ['min_weight' => 5001, 'max_weight' => null, 'rate' => 2500], // 5kg+: RM25
    ],
    'active' => true,
]);
```

### Resolving Zones for Address

```php
use AIArmada\Shipping\Services\ShippingZoneResolver;
use AIArmada\Shipping\Data\AddressData;

$resolver = app(ShippingZoneResolver::class);

$address = AddressData::from([
    'name' => 'John Doe',
    'phone' => '+60123456789',
    'line1' => '456 Customer Ave',
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

Returns use a state machine (`spatie/laravel-model-states`) with 8 states:

```
Draft → Pending → Approved → Received → Completed
  │        │          │
  │        ├── Rejected     (terminal)
  ├── Cancelled             (terminal)
           └── Expired      (terminal)
```

| State | Class | Color | Terminal |
|-------|-------|-------|----------|
| Draft | `RmaDraft` | gray | No |
| Pending | `RmaPending` | warning | No |
| Approved | `RmaApproved` | success | No |
| Rejected | `RmaRejected` | danger | Yes |
| Received | `RmaReceived` | info | No |
| Completed | `RmaCompleted` | success | Yes |
| Cancelled | `RmaCancelled` | gray | Yes |
| Expired | `RmaExpired` | warning | Yes |

### Creating an RMA

```php
use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\Enums\ReturnReason;

$rma = ReturnAuthorization::create([
    'owner_type' => $store::class,
    'owner_id' => $store->getKey(),
    'original_shipment_id' => $shipment->id,
    'customer_id' => $customer->getKey(),
    'type' => 'refund',
    'reason' => ReturnReason::Defective->value,
    'reason_details' => 'Product arrived damaged',
]);

// Add items to return
$rma->items()->create([
    'original_item_type' => $shipmentItem::class,
    'original_item_id' => $shipmentItem->getKey(),
    'sku' => $shipmentItem->sku,
    'name' => $shipmentItem->name,
    'quantity_requested' => 1,
    'reason' => ReturnReason::Defective->value,
]);
```

New RMAs start in `RmaDraft` status.

### Submitting for Review

```php
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaPending;

$rma->status->transitionTo(RmaPending::class);
```

### Approving or Rejecting

```php
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaApproved;
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaRejected;

// Approve
$rma->status->transitionTo(RmaApproved::class);
$rma->update(['approved_at' => now(), 'approved_by' => auth()->id()]);

// Reject
$rma->status->transitionTo(RmaRejected::class);
$rma->update(['rejected_at' => now(), 'rejected_by' => auth()->id()]);
```

### Marking as Received

```php
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaReceived;

$rma->status->transitionTo(RmaReceived::class);
$rma->update(['received_at' => now()]);
```

### Completing the Return

```php
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaCompleted;

$rma->status->transitionTo(RmaCompleted::class);
$rma->update(['completed_at' => now()]);
```

### Status Helpers

```php
$rma->isPending();     // status instanceof RmaPending
$rma->isApproved();    // status instanceof RmaApproved
$rma->isRejected();    // status instanceof RmaRejected
$rma->isReceived();    // status instanceof RmaReceived
$rma->isCompleted();   // status instanceof RmaCompleted
$rma->isCancelled();   // status instanceof RmaCancelled
$rma->isExpired();     // past expires_at and pending
$rma->isTerminal();    // status is final

// Scopes
ReturnAuthorization::pending()->get();
ReturnAuthorization::approved()->get();
```

## Shipment State Machine

Shipments follow a state machine workflow (12 states, `src/States`):

```
Draft → Pending → AwaitingPickup → Shipped → InTransit → OutForDelivery → Delivered
                     ↓
                 ExceptionStatus → DeliveryFailed → ReturnToSender
                     ↓
                 OnHold
                     ↓
                 Cancelled
```

Check status capabilities:

```php
use AIArmada\Shipping\States\Shipped;
use AIArmada\Shipping\States\ShipmentStatus;

$shipment->status->canTransitionTo(Shipped::class);
$shipment->status->isCancellable();
$shipment->status->isTerminal();
$shipment->status->isDelivered();
```

Shipment statuses are Spatie model states. Use the state class (for example,
`Shipped::class`) when persisting or comparing a status; there is no parallel
status enum.

Shipment `id` is the canonical internal UUID used for relations and lookups.
The separate `ulid` is a unique external/carrier-facing identifier and should
not replace the UUID in new internal code.
