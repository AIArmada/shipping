<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Data\TrackingStatus;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentDelivered;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Features\Shipments\Events\TrackingUpdated;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentEvent;
use AIArmada\Shipping\Features\Shipments\States\AwaitingPickup;
use AIArmada\Shipping\Features\Shipments\States\Delivered;
use AIArmada\Shipping\Features\Shipments\States\ExceptionStatus;
use AIArmada\Shipping\Features\Shipments\States\InTransit;
use AIArmada\Shipping\Features\Shipments\States\OutForDelivery;
use AIArmada\Shipping\Features\Shipments\States\ReturnToSender;
use AIArmada\Shipping\Features\Shipments\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\ShippingManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($shipment, $eventData, $mapper): ?ShipmentEvent {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());

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

            $latest = $shipment->events()->latest('occurred_at')->first();

            if ($latest !== null && $latest->is($event)) {
                $this->updateShipmentStatus($shipment, $normalizedStatus, CarbonImmutable::make($event->occurred_at));
            }

            event(new TrackingUpdated($shipment, collect([$event])));

            return $event;
        });
    }

    protected function updateShipmentStatus(Shipment $shipment, TrackingStatus $trackingStatus, CarbonImmutable $occurredAt): void
    {
        $statusClass = $this->resolveShipmentStatusClass($trackingStatus);

        if ($statusClass === null) {
            return;
        }

        if ($shipment->status->equals($statusClass) || ! $shipment->status->canTransitionTo($statusClass)) {
            return;
        }

        $oldStatus = $shipment->status;
        $shipment->status->transitionTo($statusClass);

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
