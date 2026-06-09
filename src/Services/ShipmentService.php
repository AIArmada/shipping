<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\Shipping\Actions\CancelShipment;
use AIArmada\Shipping\Actions\CreateShipment;
use AIArmada\Shipping\Actions\GenerateLabel;
use AIArmada\Shipping\Actions\ShipShipment;
use AIArmada\Shipping\Actions\UpdateShipmentStatus;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Enums\ShipmentStatus as ShipmentStatusEnum;
use AIArmada\Shipping\Exceptions\InvalidStatusTransitionException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentLabel;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Draft;
use AIArmada\Shipping\States\Pending;
use AIArmada\Shipping\States\ShipmentStatus as ShipmentStatusState;

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
        ShipmentStatusState | ShipmentStatusEnum | string $newStatus,
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
        $totalWeight = $shipment->items->sum(fn ($item) => $item->weight * $item->quantity);

        $shipment->update(['total_weight' => $totalWeight]);

        return $shipment->refresh();
    }
}
