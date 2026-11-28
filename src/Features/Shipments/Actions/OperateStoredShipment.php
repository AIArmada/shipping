<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentOperations;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use DomainException;

final class OperateStoredShipment implements ShipmentOperations
{
    public function ship(string $shipmentId): void
    {
        app(ShipmentService::class)->ship($this->resolve($shipmentId));
    }

    public function cancel(string $shipmentId): void
    {
        app(ShipmentService::class)->cancel($this->resolve($shipmentId));
    }

    public function syncTracking(string $shipmentId): void
    {
        $shipment = $this->resolve($shipmentId);

        if ($shipment->tracking_number === null || $shipment->tracking_number === '') {
            throw new DomainException('The shipment has no tracking number.');
        }

        app(TrackingAggregator::class)->syncTracking($shipment);
    }

    private function resolve(string $shipmentId): Shipment
    {
        return (bool) config('shipping.features.owner.enabled', false)
            ? OwnerWriteGuard::findOrFailForOwner(Shipment::class, $shipmentId)
            : Shipment::query()->findOrFail($shipmentId);
    }
}
