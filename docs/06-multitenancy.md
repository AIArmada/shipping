---
title: Multitenancy
---

# Multitenancy

The shipping package supports multi-tenant architectures using the `commerce-support` owner scoping system, allowing shipments, zones, and return authorizations to be isolated by tenant.

## Enabling Owner Mode

```php
// config/shipping.php
'features' => [
    'owner' => [
        'enabled' => env('SHIPPING_OWNER_ENABLED', false),
        'include_global' => env('SHIPPING_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('SHIPPING_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],
],
```

```env
SHIPPING_OWNER_ENABLED=true
SHIPPING_OWNER_INCLUDE_GLOBAL=true
```

> **warning**
> The default is `false` (single-tenant). Without enabling this, all tenants share the same shipment data. Always set `SHIPPING_OWNER_ENABLED=true` in multi-tenant deployments.

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
3. If the owner cannot be resolved, the query throws `NoCurrentOwnerException` rather than returning rows
4. Filament Resources enforce the same scoping server-side — UI filters are not the boundary

## Owner-Scoped Models

| Model | Owner Columns |
|-------|--------------|
| `Shipment` | `owner_type`, `owner_id` |
| `ShippingZone` | `owner_type`, `owner_id` |
| `ShippingRate` | no owner columns — scoped through its `zone` |
| `ReturnAuthorization` | `owner_type`, `owner_id` |

## Global Records

`owner_type = null` / `owner_id = null` means **global-only** — it never means "all owners".
A shipping zone with a null owner is invisible to a plain owner-scoped read and only appears
when a query opts in with `include_global` (default `false`):

```php
use AIArmada\Shipping\Models\ShippingZone;

$zones = ShippingZone::forOwner($owner, includeGlobal: true)->get();

// Global zones only
$platformZones = ShippingZone::globalOnly()->get();
```

> **info**
> `include_global` is driven by `SHIPPING_OWNER_INCLUDE_GLOBAL` (default `false`). Set it in `.env` or directly in `config/shipping.php` if your deployment uses platform-wide shared zones.

## Querying with Owner Scope

```php
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShippingZone;
use AIArmada\Shipping\States\Shipped;

// Automatically scoped (global scope applied)
$shipments = Shipment::query()->get();

// Explicit owner
$shipments = Shipment::forOwner($tenant)->get();

// Include global (platform zones)
$zones = ShippingZone::forOwner($tenant, includeGlobal: true)->get();

// System-level bypass (background jobs only)
$all = Shipment::withoutOwnerScope()->get();
```

> **warning**
> With owner mode enabled, an unresolved owner **throws** `NoCurrentOwnerException` via `OwnerContext::assertResolvedOrExplicitGlobal()` — it does not quietly return zero rows. Wrap the work in `OwnerContext::withOwner($owner, ...)` (or `withOwner(null, ...)` for explicit global work).

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
        // Verify the owner row exists; never trust a bare payload tuple
        $owner = OwnerContext::fromTypeAndIdOrFail($this->ownerType, $this->ownerId);

        OwnerContext::withOwner($owner, function (): void {
            $shipments = Shipment::query()
                ->where('status', Shipped::class)
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
