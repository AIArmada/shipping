<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Features\Shipments\Exceptions\InvalidStatusTransitionException;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentLabel;
use AIArmada\Shipping\Features\Shipments\States\Draft;
use AIArmada\Shipping\Features\Shipments\States\Pending;
use AIArmada\Shipping\Features\Shipments\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\ShippingManager;

class ShipmentService
{
    public function __construct(
        protected readonly ShippingManager $shippingManager,
    ) {}

    public function create(ShipmentData $data, ?string $ownerId = null, ?string $ownerType = null): Shipment
    {
        return CreateShipment::run($data, $ownerId, $ownerType);
    }

    public function ship(Shipment $shipment): Shipment
    {
        return ShipShipment::run($shipment);
    }

    public function updateStatus(
        Shipment $shipment,
        ShipmentStatusState | string $newStatus,
        ?string $note = null,
        ?array $eventData = null
    ): Shipment {
        return UpdateShipmentStatus::run($shipment, $newStatus, $note, null, $eventData ?? []);
    }

    public function cancel(Shipment $shipment, ?string $reason = null): Shipment
    {
        return CancelShipment::run($shipment, $reason);
    }

    public function generateLabel(Shipment $shipment, array $options = []): ShipmentLabel
    {
        return GenerateLabel::run($shipment, $options);
    }

    public function markPending(Shipment $shipment): Shipment
    {
        if (! $shipment->status->equals(Draft::class)) {
            throw new InvalidStatusTransitionException($shipment->status, new Pending($shipment));
        }

        return UpdateShipmentStatus::run($shipment, Pending::class, 'Shipment marked as pending');
    }

    public function recalculateWeight(Shipment $shipment): Shipment
    {
        return RecalculateShipmentWeight::run($shipment);
    }
}
