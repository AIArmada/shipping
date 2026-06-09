<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Enums\TrackingStatus;
use AIArmada\Shipping\Events\ShipmentDelivered;
use AIArmada\Shipping\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Events\TrackingUpdated;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentEvent;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\AwaitingPickup;
use AIArmada\Shipping\States\Delivered;
use AIArmada\Shipping\States\ExceptionStatus;
use AIArmada\Shipping\States\InTransit;
use AIArmada\Shipping\States\OutForDelivery;
use AIArmada\Shipping\States\ReturnToSender;
use AIArmada\Shipping\States\ShipmentStatus as ShipmentStatusState;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordTrackingEvent
{
    use AsAction;

    public function __construct(
        protected readonly ShippingManager $shippingManager,
        protected readonly ?StatusMapperInterface $statusMapper = null,
    ) {}

    public function handle(Shipment $shipment, TrackingEventData $eventData, ?StatusMapperInterface $mapper = null): ?ShipmentEvent
    {
        $exists = $shipment->events()
            ->where('carrier_event_code', $eventData->code)
            ->where('occurred_at', $eventData->timestamp)
            ->exists();

        if ($exists) {
            return null;
        }

        $resolvedMapper = $mapper ?? $this->statusMapper
            ?? $this->shippingManager->getStatusMapper($shipment->carrier_code);

        $normalizedStatus = $eventData->normalizedStatus
            ?? ($resolvedMapper?->map($eventData->code) ?? TrackingStatus::InTransit);

        $event = $shipment->events()->create([
            'carrier_event_code' => $eventData->code,
            'normalized_status' => $normalizedStatus,
            'description' => $eventData->description,
            'location' => $eventData->location,
            'city' => $eventData->city,
            'state' => $eventData->state,
            'country' => $eventData->country,
            'occurred_at' => $eventData->timestamp,
            'raw_data' => $eventData->raw,
        ]);

        $this->updateShipmentStatus($shipment, $normalizedStatus, CarbonImmutable::make($event->occurred_at));

        event(new TrackingUpdated($shipment, collect([$event])));

        return $event;
    }

    protected function updateShipmentStatus(Shipment $shipment, TrackingStatus $trackingStatus, CarbonImmutable $occurredAt): void
    {
        $statusClass = $this->resolveShipmentStatusClass($trackingStatus);

        if ($statusClass === null) {
            return;
        }

        if ($shipment->status->equals($statusClass)) {
            return;
        }

        $oldStatus = $shipment->status;
        $shipment->update(['status' => $statusClass]);

        if ($shipment->status->equals(Delivered::class)) {
            $shipment->update(['delivered_at' => $occurredAt]);
            event(new ShipmentDelivered($shipment));
        }

        event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));
    }

    protected function resolveShipmentStatusClass(TrackingStatus $trackingStatus): ?string
    {
        return match ($trackingStatus->getCategory()) {
            'pre_shipment' => ShipmentStatusState::normalize(AwaitingPickup::class),
            'in_transit' => ShipmentStatusState::normalize(InTransit::class),
            'out_for_delivery' => ShipmentStatusState::normalize(OutForDelivery::class),
            'delivered' => ShipmentStatusState::normalize(Delivered::class),
            'exception' => ShipmentStatusState::normalize(ExceptionStatus::class),
            'return' => ShipmentStatusState::normalize(ReturnToSender::class),
            default => null,
        };
    }
}
