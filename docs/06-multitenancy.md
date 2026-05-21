---
title: Multitenancy
---

import Aside from "@components/Aside.astro"

# Multitenancy

The shipping package supports multi-tenant architectures using the `commerce-support` owner scoping system, allowing shipments, zones, and return authorizations to be isolated by tenant.

## Enabling Owner Mode

```php
// config/shipping.php
'features' => [
    'owner' => [
        'enabled' => env('SHIPPING_OWNER_ENABLED', false),
        'include_global' => false,
        'auto_assign_on_create' => true,
    ],
],
```

```env
SHIPPING_OWNER_ENABLED=true
```

<Aside variant="warning">
  The default is `false` (single-tenant). Without enabling this, all tenants share the same shipment data. Always set `SHIPPING_OWNER_ENABLED=true` in multi-tenant deployments.
</Aside>

## Binding the Owner Resolver

Bind `OwnerResolverInterface` in `AppServiceProvider::register()`:

```php
use AIArmada\CommerceSupport\Contracts\OwnerResolverInterface;

$this->app->bind(OwnerResolverInterface::class, function () {
    return new class implements OwnerResolverInterface {
        public function resolve(): ?\Illuminate\Database\Eloquent\Model
        {
            return auth()->user()?->currentTeam;
        }
    };
});
```

## How It Works

When `owner.enabled` is `true`:

1. All queries on `Shipment`, `ShippingZone`, `ShippingRate`, and `ReturnAuthorization` are automatically scoped to the resolved owner
2. New records get `owner_type` / `owner_id` set automatically (`auto_assign_on_create`)
3. If the owner cannot be resolved, queries fail closed (return zero rows)
4. Filament Resources enforce the same scoping server-side — UI filters are not the boundary

## Owner-Scoped Models

| Model | Owner Columns |
|-------|--------------|
| `Shipment` | `owner_type`, `owner_id` |
| `ShippingZone` | `owner_type`, `owner_id` |
| `ShippingRate` | scoped via `zone` |
| `ReturnAuthorization` | `owner_type`, `owner_id` |

## Global Records

Shipping zones with `owner_id = null` are treated as shared/platform-wide zones. The default does **not** include global records in queries (`include_global = false`). To include them:

```php
use AIArmada\Shipping\Models\ShippingZone;

$zones = ShippingZone::forOwner($owner, includeGlobal: true)->get();
```

<Aside variant="info">
  `include_global` has no env override — set it in `config/shipping.php` directly if your deployment uses platform-wide shared zones.
</Aside>

## Querying with Owner Scope

```php
use AIArmada\Shipping\Models\Shipment;
use AIArmada\CommerceSupport\Support\OwnerContext;

// Automatically scoped (global scope applied)
$shipments = Shipment::query()->get();

// Explicit owner
$shipments = Shipment::forOwner($tenant)->get();

// Include global (platform zones)
$zones = ShippingZone::forOwner($tenant, includeGlobal: true)->get();

// System-level bypass (background jobs only)
$all = Shipment::withoutOwnerScope()->get();
```

## Background Jobs and Commands

Commands must not rely on ambient HTTP auth. Pass the owner explicitly:

```php
use AIArmada\CommerceSupport\Support\OwnerContext;

class GenerateShippingManifestJob implements ShouldQueue
{
    public function __construct(
        private string $ownerType,
        private string $ownerId,
    ) {}

    public function handle(): void
    {
        $owner = $this->ownerType::find($this->ownerId);

        OwnerContext::withOwner($owner, function (): void {
            $shipments = Shipment::query()
                ->where('status', ShipmentStatus::Shipped)
                ->get();

            // Process manifest...
        });
    }
}
```

## Testing

```php
use AIArmada\CommerceSupport\Contracts\OwnerResolverInterface;

it('scopes shipments to owner', function () {
    config(['shipping.features.owner.enabled' => true]);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    app()->instance(OwnerResolverInterface::class, new class($teamA) implements OwnerResolverInterface {
        public function __construct(private \Illuminate\Database\Eloquent\Model $owner) {}
        public function resolve(): ?\Illuminate\Database\Eloquent\Model { return $this->owner; }
    });

    Shipment::factory()->create(['owner_type' => $teamA->getMorphClass(), 'owner_id' => $teamA->id]);
    Shipment::factory()->create(['owner_type' => $teamB->getMorphClass(), 'owner_id' => $teamB->id]);

    expect(Shipment::query()->count())->toBe(1);
});
```
