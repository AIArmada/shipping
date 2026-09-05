---
title: Shipping Context
package: shipping
status: current
surface: domain
family: checkout-flow
keywords:
  - shipping
  - shipment
  - carrier
  - zone
  - rate
  - label
  - return
---

# Shipping Context

## Snapshot
- Composer: `aiarmada/shipping`
- Role: Carrier-agnostic shipping: shipments, zones, rates, labels, tracking, returns (Manager+drivers).
- Triggers: shipping, shipment, carrier, zone, rate, label, return
- Search first: `src/Models, src/Actions, src/Services, config, docs`
- Related: `filament-shipping`, `jnt`, `orders`, `cart`
- Paired: `filament-shipping` (Filament admin adapter)

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-shipping/CONTEXT.md` when the change crosses UI/domain
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-shipping`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Shipping abstraction or custom drivers.
- Skip when: J&T execution — see jnt.
- Owner/security: Owner-scoped (Shipment, Zone, RMA).

## Key surfaces
- Models: `ReturnAuthorization`, `ReturnAuthorizationItem`, `Shipment`, `ShipmentEvent`, `ShipmentItem`, `ShipmentLabel`, `ShipmentOperation`, `ShippingRate`, `ShippingZone`
- Actions/Services: `Actions/ApproveReturnAuthorization`, `Actions/CalculateShippingRate`, `Actions/CancelShipment`, `Actions/CreateShipment`, `Actions/GenerateLabel`, `Actions/ReconcileShipmentOperation`, `Actions/RecordTrackingEvent`, `Actions/RejectReturnAuthorization`
- Config `shipping.php`: `shipments`, `shipment_items`, `shipment_labels`, `shipment_events`, `shipping_zones`, `shipping_rates`, `return_authorizations`, `return_authorization_items`, `database`, `table_prefix`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: `05-custom-drivers.md`, `06-multitenancy.md`
