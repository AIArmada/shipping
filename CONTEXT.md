---
title: Shipping Context
package: shipping
status: current
surface: domain
family: checkout-flow
---

# Shipping Context

## Snapshot
- Composer: `aiarmada/shipping`
- Role: Carrier-agnostic shipping abstraction, shipments, zones, rates, and returns.
- Search first: `src/Models`, `src/Actions`, `src/Services`, `src/Events`, `config`, `docs`
- Related: `filament-shipping`, `jnt`, `orders`, `cart`

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-shipping/CONTEXT.md` when admin UI changes are involved
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-shipping`.
- Update `docs/*.md` in the same pass when public behavior or config changes.
