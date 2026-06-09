## Second pass — 2026-06-09

### Confirmed

- **Phase 1** (manager/service/engine boundary): `ShippingManager` is the implementation (Laravel Manager pattern for drivers). `ShipmentService` delegates all mutations to Actions — confirmed via source. The triad is clarified.
- **Phase 2** (extract mutations to Actions): 9 Actions exist — `CreateShipment`, `UpdateShipmentStatus`, `CancelShipment`, `RecordTrackingEvent`, `ShipShipment`, `GenerateLabel`, `ApproveReturnAuthorization`, `RejectReturnAuthorization`, `CalculateShippingRate`. `ShipmentService` is pure delegation. Tests exist at `packages/shipping/tests/Feature/CreateShipmentTest.php` and `RecordTrackingEventTest.php`.
- **Phase 3** (free-shipping and zone strategies): `Contracts/FreeShippingPolicyInterface` + `Support/FreeShippingPolicyRegistry` + `Strategies/ThresholdFreeShippingPolicy` exist. `Contracts/ZoneResolutionStrategyInterface` + `Support/ZoneResolutionStrategyRegistry` + `Strategies/GeoZoneResolutionStrategy` exist. Both evaluators (`FreeShippingEvaluator`, `ShippingZoneResolver`) accept registries via constructor.
- **Phase 4** (retry helper): Decision documented — `RetryService` stays shipping-local for now.

### Still open

- All phases are marked `[done]` and verified complete. No open items.

### New findings

- `RecordTrackingEvent` Action was added but wasn't in the original recommendation — it's a good addition.
- The `ShippingManager` is a proper Laravel Manager pattern with custom driver creators and status mappers, not a thin facade — this is a solid architectural choice.
- The service layer is clean: `ShipmentService` is now 74 lines of pure delegation to Actions. This is the pattern every package should follow.

### Updated recommendation

No further action needed on shipping. The package is in excellent shape with clean separation between the Manager (driver selection), Services (read-side + delegation), and Actions (mutations).

---

# Shipping friendliness review

This note reviews `packages/shipping` against two repo-level expectations:

- when a capability may grow variants, prefer stable seams such as contracts, metadata, hooks, domain events, resolvers, and support classes
- when orchestration repeats, extract reusable Actions, Services, or Use Cases so the package stays friendly to multiple entrypoints

## What I reviewed

- `src/Services` (8 classes)
- `src/Actions` (5 classes)
- `src/Drivers` (4 classes)
- `src/Strategies` (4 classes)
- `src/Integrations`
- `src/Cart`
- `src/Http/Controllers`
- `routes/web.php`
- downstream consumers in `cart`, `checkout`, `orders`, `jnt`

## What is already friendly

### Real driver adapter seam

- `Drivers/FlatRateShippingDriver.php`
- `Drivers/ManualShippingDriver.php`
- `Drivers/NullShippingDriver.php`
- `Drivers/ZoneBasedShippingDriver.php` (all impl `Contracts/ShippingDriverInterface.php`)

This is the right shape. Adding a new carrier (or a new shipping model like JNT) is a new driver behind the contract.

### Real rate selection strategies

- `Strategies/CheapestRateStrategy.php`
- `Strategies/FastestRateStrategy.php`
- `Strategies/BalancedRateStrategy.php`
- `Strategies/PreferredCarrierStrategy.php` (all impl `Contracts/RateSelectionStrategyInterface.php`)

Each strategy is a separate class. Adding a new selection rule is additive.

### Orders integration is a real adapter

- `Integrations/OrderFulfillmentHandler.php` (impl `orders/Contracts/FulfillmentHandlerInterface.php`)

The package exposes an `OrderFulfillmentHandler` rather than reaching into `Order` directly.

### Cart integration is isolated

- `Cart/ShippingCondition.php`
- `Cart/ShippingConditionProvider.php`

Cart composes shipping through a provider, not by reaching into the shipping model.

### Money is a typed DTO

- `Data/RateQuoteData.php`
- `Data/ShipmentData.php` (and 8 more)

DTOs are typed via spatie/laravel-data. Callers can rely on the shape.

## Findings

### 1. `ShippingManager` is a top-level orchestrator

**Files**

- `src/ShippingManager.php`
- `src/Services/ShipmentService.php`
- `src/Services/RateShoppingEngine.php`

**Why this hurts friendliness**

`ShippingManager` likely owns the public entry point for all shipping operations. `ShipmentService` and `RateShoppingEngine` are siblings. The boundaries between "manager," "service," and "engine" are unclear.

**Recommendation**

Pick a single canonical orchestration surface. Either:

- keep `ShippingManager` as a thin facade and move mutations to Actions, or
- promote `ShipmentService` to the public surface and remove `ShippingManager`

Today the manager + service + engine triad is three names for overlapping concerns.

### 2. Service count is high (8) for a package with only 5 Actions

**Files in `src/Services/`**

- `ShipmentService`
- `RateShoppingEngine`
- `BatchRateLimiter`
- `FreeShippingEvaluator`
- `ShippingZoneResolver`
- `TrackingAggregator`
- `RetryService`

**Why this hurts friendliness**

Mutations are split between services and Actions, but most mutations likely live in services. This is inconsistent with the monorepo's "Actions only" rule.

**Recommendation**

Move all shipment mutations to Actions:

- `Actions/CreateShipment` (exists)
- `Actions/UpdateShipmentStatus` (exists)
- `Actions/CancelShipment`
- `Actions/ApplyReturnAuthorization` (exists)
- `Actions/RecordTrackingEvent`
- `Actions/ResolveReturnAuthorization` (exists)

Services become read-side (queries, tracking, zone resolution, rate shopping).

### 3. Free shipping is its own service but the policy is config-driven

**Files**

- `src/Services/FreeShippingEvaluator.php`
- `src/Services/FreeShippingResult.php`

**Why this hurts friendliness**

Free-shipping evaluation is a real business rule. As the package supports more promotional shipping (first-class free, threshold-based, member-only), the evaluator will grow.

**Recommendation**

Move free-shipping evaluation behind a `FreeShippingPolicyInterface` and a registry. The promotion package can register its own policies.

### 4. Zone resolution is a service but not a clear strategy

**Files**

- `src/Services/ShippingZoneResolver.php`
- `src/Models/ShippingZone.php`

**Why this hurts friendliness**

Zone resolution (which zone applies for a given address, customer, or cart) is a variant-heavy area. New resolution rules (B2B zones, international zones, product-class zones) will edit the same class.

**Recommendation**

Extract a `ZoneResolutionStrategyInterface` and one strategy per rule. The resolver coordinates them. The strategy is configurable per merchant.

### 5. Retry logic is a service but not a shared seam

**Files**

- `src/Services/RetryService.php`

**Why this hurts friendliness**

`RetryService` is shipping-specific today. If chip, cashier-chip, or jnt also need retry, the logic will be copied.

**Recommendation**

If a second package demonstrates the same need, move the retry helper to `commerce-support` and make shipping use it.

### 6. Routes file is a single signed URL

**Files**

- `routes/web.php`
- `src/Http/Controllers/LabelController.php`

**Why this hurts friendliness**

This is fine for the current state. Note that adding more routes (tracking lookup, return portal) will edit the same file. Consider grouping controller routes under a prefix to make the boundary clear.

### 7. Status mapping contract exists but the event is the only consumer

**Files**

- `Contracts/StatusMapperInterface.php`
- `Events/ShipmentStatusChanged.php`
- `Events/TrackingUpdated.php`

**Why this hurts friendliness**

The status mapper contract exists, but its consumers are unclear. New carriers need to map their status to the package's status, and that mapping should be in one place.

**Recommendation**

Each driver (or a shared adapter for the driver family) should implement the status mapper. The mapping is registered per driver, not hard-coded in the central service.

## Concrete refactor plan

### Phase 1 — clarify the manager/service/engine boundary

**Steps**

1. Decide whether `ShippingManager` is the facade or the implementation.
2. Move the other's logic into the chosen one.
3. Make the unused one a thin compat adapter.

### Phase 2 — extract mutations to Actions

**Steps**

1. Move all shipment mutations from services to Actions.
2. Update callers (controllers, listeners, filament, jnt).
3. Add tests for each Action.

### Phase 3 — extract free-shipping and zone strategies

**Steps**

1. Add `Contracts/FreeShippingPolicyInterface` and a registry.
2. Add `Contracts/ZoneResolutionStrategyInterface` and a registry.
3. Update `FreeShippingEvaluator` and `ShippingZoneResolver` to use the registries.

### Phase 4 — move retry helper to foundation if needed

**Steps**

1. Wait for evidence that another package needs the same retry behavior.
2. If yes, extract `commerce-support/Support/RetryPolicy` and migrate.





## Refactor tracking

This checklist tracks progress on the refactor plan above. Each item lists a concrete phase/step.
Agents: claim an item by updating its status. Use `@agent-name` to claim ownership.

Status legend:
- `[pending]` — not started
- `[in-progress]` — being worked on
- `[done]` — completed and verified
- `[blocked]` — blocked by another item

### Phase 1 — clarify the manager/service/engine boundary

- [done] `ShippingManager` is the implementation (Laravel Manager pattern for drivers).
- [done] `ShipmentService` is the thin compat adapter — delegates all mutations to Actions.
- [done] `ShipmentService::create()` now delegates to `CreateShipment::run()` (was inline).

### Phase 2 — extract mutations to Actions

- [done] `CreateShipment` accepts `ShipmentData` DTO, handles items/events/weight recalculation.
- [done] `RecordTrackingEvent` Action created (idempotent, deduplicates by code+timestamp).
- [done] `ShipmentService` is now pure delegation (all 5 mutation methods → Actions).
- [done] `OrderFulfillmentHandler` uses `ShipmentService` which delegates — no changes needed.
- [done] Added `CreateShipmentTest` (3 tests: basic, empty items, default currency).
- [done] Added `RecordTrackingEventTest` (3 tests: record, dedup, status update).

### Phase 3 — extract free-shipping and zone strategies

- [done] Add `Contracts/FreeShippingPolicyInterface` and `Support/FreeShippingPolicyRegistry`.
- [done] Add `Contracts/ZoneResolutionStrategyInterface` and `Support/ZoneResolutionStrategyRegistry`.
- [done] Add `Strategies/ThresholdFreeShippingPolicy` (default threshold-based policy).
- [done] Add `Strategies/GeoZoneResolutionStrategy` (default geo-matching strategy).
- [done] Update `FreeShippingEvaluator` to delegate to registry (constructor accepts registry + config).
- [done] Update `ShippingZoneResolver` to use registry (constructor accepts registry, delegates zone matching).
- [done] Register defaults in `ShippingServiceProvider`.

### Phase 4 — move retry helper to foundation if needed

- [done] Decision documented: `RetryService` stays in shipping for now. No evidence yet that another package needs the same retry behavior. If chip, cashier-chip, or jnt demonstrate the same need, extract `commerce-support/Support/RetryPolicy` and migrate in a follow-up pass.



## Suggested verification scope

- per-Action tests for new mutation Actions
- driver and strategy tests
- zone resolution tests after extraction
- cross-package tests for cart/checkout/orders/jnt after refactor

## Recommended first move

Phase 1 — clarify the manager/service/engine boundary. The triad is the most visible architectural smell in the package, and resolving it is mostly mechanical.
