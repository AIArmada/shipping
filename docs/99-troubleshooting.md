---
title: Troubleshooting
---

# Troubleshooting

## Common Issues

### "Driver [xyz] not supported"

**Cause**: The driver hasn't been registered with the ShippingManager.

**Solution**: 
1. Check that the driver is listed in `config/shipping.php` under `drivers`.
2. If using a custom driver, ensure it's registered via `Shipping::extend()` in a service provider.
3. Verify the service provider is loaded in `config/app.php` or via package discovery.

```php
// Check available drivers
use AIArmada\Shipping\Facades\Shipping;

dd(Shipping::getAvailableDrivers());
// Should return ['null', 'manual', 'flat_rate', 'your_driver']
```

### Rate Shopping Returns Empty Rates

**Cause**: No drivers service the destination address, or all API calls failed.

**Solutions**:
1. Check that carriers service the destination country/region:
   ```php
   $driver = Shipping::driver('jnt');
   $services = $driver->servicesDestination($address);
   // Returns true/false
   ```
2. Check driver capabilities:
   ```php
   $driver->supports(DriverCapability::RateQuotes);
   ```
3. Enable fallback driver in config:
   ```php
   'rate_shopping' => [
       'fallback_driver' => 'manual',
   ],
   ```

### Free Shipping Not Applying

**Cause**: Configuration issue or cart type mismatch.

**Solutions**:
1. Verify free shipping is enabled:
   ```php
   config('shipping.free_shipping.enabled'); // Should be true
   ```
2. Check threshold is set correctly (in cents):
   ```php
   config('shipping.free_shipping.threshold'); // e.g., 15000 for RM150
   ```
3. Ensure you're passing the correct value to the evaluator:
   ```php
   $evaluator = app(FreeShippingEvaluator::class);
   // Pass cents, not ringgit
   $result = $evaluator->evaluate(12000); // RM120 in cents
   ```

### Shipment Status Not Updating

**Cause**: Tracking sync not running or carrier API issues.

**Solutions**:
1. Manually trigger tracking sync:
   ```php
   use AIArmada\Shipping\Services\TrackingAggregator;
   
   $aggregator = app(TrackingAggregator::class);
   $results = $aggregator->syncAll();
   dd($results);
   ```
2. Check shipment age (old shipments stop syncing):
   ```php
   config('shipping.tracking.max_sync_age_days'); // Default 30
   ```
3. Verify carrier driver implements tracking:
   ```php
   Shipping::driver('jnt')->supports(DriverCapability::Tracking);
   ```

### Multi-Tenancy Data Leaks

**Cause**: Owner scoping not enabled or incorrectly configured.

**Solutions**:
1. Enable owner scoping:
   ```php
   // config/shipping.php
   'features' => [
       'owner' => [
           'enabled' => true,
           'include_global' => false, // Set to true only if needed
       ],
   ],
   ```
2. Ensure `OwnerContext` is set in middleware:
   ```php
   use AIArmada\CommerceSupport\Support\OwnerContext;
   
   // In your middleware
   OwnerContext::set($tenant);
   ```
3. Verify queries use owner scoping:
   ```php
   // Correct
   Shipment::forOwner($owner)->get();
   
   // Wrong - bypasses owner scope
   Shipment::all();
   ```

### Labels Not Generating

**Cause**: Driver doesn't support label generation or API error.

**Solutions**:
1. Check driver capability:
   ```php
   Shipping::driver('jnt')->supports(DriverCapability::LabelGeneration);
   ```
2. For manual driver, labels aren't supported—use a real carrier driver.
3. Check for API errors in logs:
   ```php
   // Enable debug logging temporarily
   Log::debug('Label generation', [
       'shipment' => $shipment->toArray(),
   ]);
   ```

### Zone Matching Fails

**Cause**: Address doesn't match any active zone.

**Solutions**:
1. Debug zone resolution:
   ```php
   use AIArmada\Shipping\Services\ShippingZoneResolver;
   
   $resolver = app(ShippingZoneResolver::class);
   $zone = $resolver->resolve($address);
   
   if ($zone === null) {
       // Check all zones
       $zones = ShippingZone::active()->get();
       foreach ($zones as $zone) {
           echo "{$zone->name}: " . ($zone->matchesAddress($address) ? 'YES' : 'NO');
       }
   }
   ```
2. Verify zone configuration:
   - Country zones need correct ISO codes (e.g., 'MY', not 'Malaysia')
   - Postcode ranges need proper formatting: '40000-48000, 50000-59999'
   - State names must match exactly

### Table Rate Returns Zero

**Known Issue**: Table-based rate calculation is not fully implemented.

**Workaround**: Use `flat`, `per_kg`, or `per_item` rate types instead until table rates are implemented.

## Performance Issues

### Slow Rate Shopping

**Cause**: Sequential carrier API calls.

**Solutions**:
1. Ensure concurrent fetching is working (requires Laravel Concurrency):
   ```php
   // Check if Concurrency facade is available
   class_exists(\Illuminate\Support\Facades\Concurrency::class);
   ```
2. Enable rate caching:
   ```php
   'rate_shopping' => [
       'cache_ttl' => 5, // Minutes
   ],
   ```
3. Limit carriers queried:
   ```php
   $engine = app(RateShoppingEngine::class);
   $rates = $engine->getAllRates($address, $packages, drivers: ['jnt', 'poslaju']);
   ```

### Database Query Performance

Add indexes for frequently filtered columns:

```php
Schema::table('shipping_shipments', function (Blueprint $table) {
    $table->index('status');
    $table->index('carrier_code');
    $table->index(['owner_type', 'owner_id']);
    $table->index('created_at');
});
```

## Debug Mode

Enable detailed logging:

```php
// In a service provider
use Illuminate\Support\Facades\Log;
use AIArmada\Shipping\Events\ShipmentCreated;
use AIArmada\Shipping\Events\ShipmentStatusChanged;

Event::listen(ShipmentCreated::class, function ($event) {
    Log::info('Shipment created', ['id' => $event->shipment->id]);
});

Event::listen(ShipmentStatusChanged::class, function ($event) {
    Log::info('Shipment status changed', [
        'id' => $event->shipment->id,
        'from' => $event->oldStatus,
        'to' => $event->newStatus,
    ]);
});
```

## Getting Help

1. Check the [vision documents](vision/) for architectural details
2. Review test files in `tests/src/Shipping/` for usage examples
3. Open an issue on the [GitHub repository](https://github.com/aiarmada/commerce/issues)
