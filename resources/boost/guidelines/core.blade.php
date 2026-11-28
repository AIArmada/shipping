# Shipping

Shipping is a modular Dune package. Rates owns zones, rate configuration and quote calculation; Shipments owns shipment lifecycle and tracking; Returns owns return authorizations and package-unit returns; Reporting exposes read-only dashboard statistics.

- Resolve `AIArmada\Shipping\Features\Rates\Api\CalculateRates` from the container for checkout quotes. Pass `Shipping\Data\AddressData` and `PackageData` values and consume the list of `RateQuoteData` values.
- Use `Rates\Api\ManageRateConfiguration` for rate and zone mutations, `Shipments\Api\ShipmentOperations` for shipping, cancellation and tracking, and `Shipments\Api\ManageManualShipment` for manual shipment administration (all feature namespaces are under `AIArmada\Shipping\Features`).
- Use `Returns\Api\ManageReturnAuthorization` and `Returns\Api\PackageReturnWorkflow` for returns; preserve complete composite units and their order-time composition.
- Obtain printable labels through `Shipments\Api\PrintableLabel` with the shipment identifier and authenticated user identifier. Do not bypass signed-download authorization.
- Preserve owner scoping, integer minor-unit prices and carrier-operation idempotency. Do not mutate shipping tables from sibling packages or Filament callbacks. Implement carrier adapters against `AIArmada\Shipping\Contracts\ShippingDriverInterface`.
