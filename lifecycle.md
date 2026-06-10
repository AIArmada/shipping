# Shipping Package — Lifecycle Audit & Refactoring Plan

## 1. Executive Summary

The shipping package (`aiarmada/shipping`) models carrier-agnostic shipments, zones, rates, and returns. **8 tables** across 7 migrations. The core lifecycle concern is the `Shipment` entity, which already uses `spatie/model-states` with a well-defined state machine. `ReturnAuthorization` uses a raw string status without a state machine, and lifecycle timestamps are missing for key business-critical status transitions.

**No backward compatibility is required.** Target PostgreSQL with `timestampTz`.

---

## 2. Full Inventory by Table

### 2.1 `shipments`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `ulid` | `ulid` | Unique, auto-generated |
| `owner_type`, `owner_id` | `nullableMorphs` | Multi-tenant owner |
| `shippable_type`, `shippable_id` | `nullableMorphs` | Polymorphic source (order, cart, RMA) |
| `reference` | `string` | Indexed |
| `carrier_code` | `string(50)` | Indexed |
| `service_code` | `string(50)` | Nullable |
| `tracking_number` | `string` | Nullable, indexed |
| `carrier_reference` | `string` | Nullable |
| `status` | `string(50)` | State machine via `spatie/model-states` |
| `origin_address` | `jsonb` | |
| `destination_address` | `jsonb` | |
| `package_count` | `unsignedInteger` | Default 1 |
| `total_weight` | `unsignedInteger` | Grams, default 0 |
| `declared_value` | `unsignedInteger` | Minor currency, default 0 |
| `currency` | `string(3)` | Default MYR |
| `shipping_cost` | `unsignedInteger` | Minor currency, default 0 |
| `insurance_cost` | `unsignedInteger` | Minor currency, default 0 |
| `cod_amount` | `unsignedInteger` | Nullable |
| `label_url` | `string` | Redundant with shipment_labels |
| `label_format` | `string(10)` | Redundant with shipment_labels |
| `shipped_at` | `timestampTz` | Lifecycle timestamp |
| `estimated_delivery_at` | `timestampTz` | Lifecycle timestamp |
| `delivered_at` | `timestampTz` | Lifecycle timestamp |
| `last_tracking_sync` | `timestampTz` | Operational |
| `metadata` | `jsonb` | |
| `created_at`, `updated_at` | `timestampTz` | |

**State machine (12 states):** Draft → Pending → AwaitingPickup → Shipped → InTransit → OutForDelivery → Delivered. Side states: Cancelled, OnHold, Exception, DeliveryFailed, ReturnToSender.

### 2.2 `shipment_items`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `shipment_id` | `foreignUuid` | |
| `shippable_item_type`, `shippable_item_id` | `nullableMorphs` | Polymorphic source item |
| `sku` | `string` | Nullable |
| `name` | `string` | |
| `description` | `text` | Nullable |
| `quantity` | `unsignedInteger` | Default 1 |
| `weight` | `unsignedInteger` | Grams, default 0 |
| `declared_value` | `unsignedInteger` | Minor currency, default 0 |
| `hs_code` | `string` | Nullable |
| `origin_country` | `string(3)` | Nullable |
| `metadata` | `jsonb` | |
| `created_at`, `updated_at` | `timestampTz` | |

No lifecycle issues. Stateless line item.

### 2.3 `shipment_events`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `shipment_id` | `foreignUuid` | |
| `carrier_event_code` | `string(50)` | Nullable |
| `normalized_status` | `string(50)` | Indexed, enum-backed |
| `description` | `text` | Nullable |
| `location` | `string` | Nullable |
| `city` | `string` | Nullable |
| `state` | `string` | Nullable |
| `country` | `string(2)` | Nullable |
| `postcode` | `string(20)` | Nullable |
| `occurred_at` | `timestampTz` | Indexed |
| `raw_data` | `jsonb` | |
| `created_at`, `updated_at` | `timestampTz` | |

No lifecycle issues. Immutable event log.

### 2.4 `shipment_labels`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `shipment_id` | `foreignUuid` | |
| `format` | `string(10)` | |
| `size` | `string(10)` | Nullable |
| `url` | `string` | Nullable |
| `content` | `longText` | Nullable, base64 |
| `generated_at` | `timestampTz` | |
| `created_at`, `updated_at` | `timestampTz` | |

No lifecycle issues.

### 2.5 `shipping_zones`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `owner_type`, `owner_id` | `nullableMorphs` | Multi-tenant owner |
| `name` | `string` | |
| `code` | `string(50)` | |
| `type` | `string(20)` | country/state/postcode/radius |
| `countries` | `jsonb` | Nullable |
| `states` | `jsonb` | Nullable |
| `postcode_ranges` | `jsonb` | Nullable |
| `center_lat` | `decimal(10,8)` | Nullable |
| `center_lng` | `decimal(11,8)` | Nullable |
| `radius_km` | `unsignedInteger` | Nullable |
| `priority` | `unsignedInteger` | Default 0 |
| `is_default` | `boolean` | Designation, not lifecycle — kept as-is |
| `active` | `boolean` | Admin config toggle — acceptable as-is |
| `created_at`, `updated_at` | `timestampTz` | |

### 2.6 `shipping_rates`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `zone_id` | `foreignUuid` | |
| `carrier_code` | `string(50)` | Nullable |
| `method_code` | `string(50)` | |
| `name` | `string` | |
| `description` | `text` | Nullable |
| `calculation_type` | `string(20)` | flat/per_kg/per_item/percentage/table |
| `base_rate` | `unsignedInteger` | Minor currency, default 0 |
| `per_unit_rate` | `unsignedInteger` | Minor currency, default 0 |
| `min_charge` | `unsignedInteger` | Nullable |
| `max_charge` | `unsignedInteger` | Nullable |
| `free_shipping_threshold` | `unsignedInteger` | Nullable |
| `rate_table` | `jsonb` | Nullable |
| `estimated_days_min` | `unsignedTinyInteger` | Nullable |
| `estimated_days_max` | `unsignedTinyInteger` | Nullable |
| `conditions` | `jsonb` | Nullable |
| `active` | `boolean` | Admin config toggle — acceptable as-is |
| `created_at`, `updated_at` | `timestampTz` | |

### 2.7 `return_authorizations`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `owner_type`, `owner_id` | `nullableMorphs` | Multi-tenant owner |
| `rma_number` | `string` | Unique |
| `original_shipment_id` | `foreignUuid` | Nullable |
| `order_reference` | `string` | Nullable |
| `customer_id` | `foreignUuid` | Nullable |
| `status` | `string(50)` | Raw string, no state machine |
| `type` | `string(50)` | |
| `reason` | `string(100)` | |
| `reason_details` | `text` | Nullable |
| `approved_by` | `foreignUuid` | Nullable |
| `rejected_by` | `foreignUuid` | Nullable |
| `approved_at` | `timestampTz` | Lifecycle timestamp |
| `rejected_at` | `timestampTz` | Lifecycle timestamp |
| `received_at` | `timestampTz` | Lifecycle timestamp |
| `completed_at` | `timestampTz` | Lifecycle timestamp |
| `expires_at` | `timestampTz` | |
| `metadata` | `jsonb` | |
| `created_at`, `updated_at` | `timestampTz` | |

### 2.8 `return_authorization_items`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `return_authorization_id` | `foreignUuid` | |
| `original_item_type`, `original_item_id` | `nullableMorphs` | Polymorphic source item |
| `sku` | `string` | Nullable |
| `name` | `string` | |
| `quantity_requested` | `unsignedInteger` | Default 1 |
| `quantity_approved` | `unsignedInteger` | Default 0 |
| `quantity_received` | `unsignedInteger` | Default 0 |
| `reason` | `string(100)` | Nullable |
| `condition` | `string(50)` | Nullable |
| `metadata` | `jsonb` | |
| `created_at`, `updated_at` | `timestampTz` | |

No lifecycle issues. Stateless line item.

---

## 3. Problems Summary

### P1 — `return_authorizations.status` is raw string, not state machine

Unlike `shipments.status` which uses `spatie/model-states`, `return_authorizations.status` is a raw string with manual `isPending()`, `isApproved()`, etc. helpers. This is inconsistent and fragile.

Current statuses: `pending`, `approved`, `rejected`, `received`, `completed`, `cancelled`, `expired` (derived).

**Fix:** Create a `ReturnAuthorizationStatus` abstract state class + concrete state classes in `src/States/`, mirroring the `ShipmentStatus` pattern. Wire up `StateConfig` with allowed transitions. Convert `isExpired()` to a proper state based on `expires_at` passage. spatie/model-states is appropriate here — 7+ states with guarded transitions.

### P2 — Missing business-critical lifecycle timestamps on `shipments`

Shipment has 12 states but only tracks `shipped_at`, `delivered_at`, and `estimated_delivery_at`. Business-critical timestamps are missing for terminal/failure states.

| Missing Column | Trigger Event |
|---|---|
| `cancelled_at` | Any → Cancelled |
| `delivery_failed_at` | Any → DeliveryFailed |

**Fix:** Add these 2 `timestampTz` columns. Transient/operational states (AwaitingPickup, InTransit, OutForDelivery, OnHold, Exception, ReturnToSender) do not need dedicated timestamps — those state entries are already tracked in `shipment_events`.

### P3 — Missing lifecycle timestamp on `return_authorizations`

| Missing Column | Trigger Event |
|---|---|
| `cancelled_at` | Any → Cancelled |

**Fix:** Add `cancelled_at timestampTz` nullable.

### P4 — Redundancy: `shipments.label_url` / `shipments.label_format`

These columns duplicate information in `shipment_labels`. `Shipment::labels()` is a `HasMany` so there can be multiple labels per shipment. The columns on `shipments` appear to be denormalized convenience copies of the "primary" label.

**Fix:** Remove `label_url` and `label_format` from `shipments`. Any code referencing them should use `$shipment->labels()->latest('generated_at')->first()?->url` instead.

---

## 4. Recommended Structure

### Target Schema — `shipments`

```
status               string(50)       [state machine — spatie/model-states]
shipped_at            timestampTz     [existing]
delivery_failed_at    timestampTz     [new] *→DeliveryFailed
delivered_at          timestampTz     [existing]
cancelled_at          timestampTz     [new] *→Cancelled
estimated_delivery_at timestampTz     [existing]
last_tracking_sync    timestampTz     [existing]
// REMOVED: label_url, label_format
```

### Target Schema — `shipping_zones`

```
// Kept as-is. `active` boolean is an admin config toggle (not lifecycle).
// `is_default` boolean is a designation (not lifecycle).
```

### Target Schema — `shipping_rates`

```
// Kept as-is. `active` boolean is an admin config toggle (not lifecycle).
```

### Target Schema — `return_authorizations`

```
status         string(50)    [now state machine — spatie/model-states]
cancelled_at   timestampTz   [new]
approved_at    timestampTz   [existing]
rejected_at    timestampTz   [existing]
received_at    timestampTz   [existing]
completed_at   timestampTz   [existing]
expires_at     timestampTz   [existing]
```

### Target State Machine — `ReturnAuthorizationStatus`

States: `Draft` → `Pending` → `Approved`/`Rejected` → `Received` → `Completed`. Side: `Cancelled`, `Expired`.

Transitions:
```
Draft    → Pending, Cancelled
Pending  → Approved, Rejected, Cancelled, Expired
Approved → Received, Cancelled, Expired
Received → Completed
Rejected → [terminal]
Cancelled → [terminal]
Expired   → [terminal]
Completed → [terminal]
```

---

## 5. Refactoring Plan — Parallel-Agent Checklist

### Agent A: Shipment lifecycle timestamps

- [x] Create migration adding 2 timestampTz columns to `shipments`: `cancelled_at`, `delivery_failed_at`.
- [x] Update `Shipment` model: add new columns to `$fillable`, `casts()`, PHPDoc.
- [x] Create a `RecordShipmentTimestamps` action/listener that sets the appropriate `*_at` column when `ShipmentStatus` transitions to Cancelled or DeliveryFailed.
- [x] Update `ShipmentStatus` base state class to accept an optional `$occurredAt` and dispatch an event.
- [x] Write/update Pest tests for each transition confirming the timestamp is set.

### Agent B: Remove `label_url` / `label_format` from shipments

- [x] Create migration dropping `label_url` and `label_format` from `shipments`.
- [x] Update `Shipment` model: remove from `$fillable`, `casts()`, PHPDoc.
- [x] Grep for `label_url` and `label_format` usage across all packages (`commerce-support`, `orders`, `filament-shipping`, `jnt`, etc.) and update call sites to use `$shipment->labels()->latest('generated_at')->first()?->url`.
- [x] Write/update Pest tests.

### Agent C: ReturnAuthorization state machine

- [x] Create `ReturnAuthorizationStatus` abstract state class in `src/States/`.
- [x] Create concrete state classes: `RmaDraft`, `RmaPending`, `RmaApproved`, `RmaRejected`, `RmaReceived`, `RmaCompleted`, `RmaCancelled`, `RmaExpired`.
- [x] Implement `StateConfig` with allowed transitions.
- [x] Add `cancelled_at` column to `return_authorizations` migration.
- [x] Update `ReturnAuthorization` model: use `HasStates` trait, update casts, remove manual `is*()` helpers, update `isExpired()` to use state logic.
- [x] Create listener/action for populating lifecycle timestamps on state transition.
- [x] Write/update Pest tests.

### Agent D: Cross-cutting verification

- [x] Run PHPStan on `packages/shipping/src`.
- [x] Run Pint on `packages/shipping/src`.
- [x] Run Pest tests: `./vendor/bin/pest --parallel packages/shipping/tests`.
- [x] Grep for stale references to removed columns across entire monorepo.
- [x] Update config `shipping.php` if needed.
- [x] Update docs in `packages/shipping/docs/`.

---

## 6. Migration Strategy

### Order of operations

1. **Add** columns first:
   - `2001_02_01_000008_add_lifecycle_timestamps_to_shipments.php`
   - `2001_02_01_000009_add_cancelled_at_to_return_authorizations.php`

2. **Drop** old columns (separate migration, after code deploy):
   - `2001_02_01_000010_drop_label_columns_from_shipments.php`

3. New `*_at` columns default to NULL, so existing rows are unaffected. State transition timestamps populate only for transitions that occur after deploy.

### Rollback plan

No `down()` methods required per convention. Each migration is additive except the final drops — those are reversible if needed by restoring from backup.

---

## 7. Verification Commands

```bash
# Per-package PHPStan
./vendor/bin/phpstan analyse packages/shipping/src --level=6

# Per-package Pint
./vendor/bin/pint packages/shipping/src --test

# Pest tests (always --parallel)
./vendor/bin/pest --parallel packages/shipping/tests

# Grep: label_url / label_format references
rg -n "label_url|label_format" packages/*/src packages/*/config

# Grep: unscoped queries in shipping
rg -n "::query\(|->query\(|getEloquentQuery\(" packages/shipping/src

# Grep: DB::table usage in shipping
rg -n "DB::table\(" packages/shipping/src

# Full test sweep
./vendor/bin/pest --parallel --filter=shipping

# Migration dry-run check (PostgreSQL)
php artisan migrate:status
```
