<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Enums\DriverCapability;
use AIArmada\Shipping\Enums\TrackingStatus;
use AIArmada\Shipping\Events\ShipmentDelivered;
use AIArmada\Shipping\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Events\TrackingUpdated;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentEvent;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\AwaitingPickup;
use AIArmada\Shipping\States\Cancelled;
use AIArmada\Shipping\States\Delivered;
use AIArmada\Shipping\States\ExceptionStatus;
use AIArmada\Shipping\States\InTransit;
use AIArmada\Shipping\States\OutForDelivery;
use AIArmada\Shipping\States\ReturnToSender;
use AIArmada\Shipping\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\Support\ShippingOwnerScope;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Aggregates tracking across all carriers with normalized statuses.
 */
class TrackingAggregator
{
    /**
     * @var array<string, StatusMapperInterface>
     */
    protected array $statusMappers = [];

    public function __construct(
        protected readonly ShippingManager $shippingManager
    ) {}

    /**
     * Register a status mapper for a carrier.
     */
    public function registerMapper(StatusMapperInterface $mapper): self
    {
        $this->statusMappers[$mapper->getCarrierCode()] = $mapper;

        return $this;
    }

    /**
     * Get registered status mapper for a carrier.
     */
    public function getMapper(string $carrierCode): ?StatusMapperInterface
    {
        return $this->statusMappers[$carrierCode] ?? null;
    }

    /**
     * Sync tracking for a shipment.
     */
    public function syncTracking(Shipment $shipment): Shipment
    {
        if ($shipment->tracking_number === null) {
            return $shipment;
        }

        $driver = $this->shippingManager->driver($shipment->carrier_code);

        if (! $driver->supports(DriverCapability::Tracking)) {
            return $shipment;
        }

        $trackingData = $driver->track($shipment->tracking_number);

        $newEvents = $this->processTrackingEvents($shipment, $trackingData->events);

        $shipment->update(['last_tracking_sync' => now()]);

        if ($newEvents->isNotEmpty()) {
            $this->updateShipmentStatus($shipment);
            event(new TrackingUpdated($shipment, $newEvents));
        }

        return $shipment->refresh();
    }

    /**
     * Sync tracking for multiple shipments.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return Collection<int, array{id: int, success: bool, error?: string}>
     */
    public function syncBatch(Collection $shipments): Collection
    {
        $results = collect();

        foreach ($shipments as $shipment) {
            try {
                $this->syncTracking($shipment);
                $results->push(['id' => $shipment->id, 'success' => true]);
            } catch (Throwable $e) {
                $results->push(['id' => $shipment->id, 'success' => false, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $results;
    }

    /**
     * Get shipments that need tracking updates.
     *
     * @return Collection<int, Shipment>
     */
    public function getShipmentsNeedingUpdate(int $limit = 100): Collection
    {
        $maxAge = config('shipping.tracking.max_tracking_age', 30);
        $syncInterval = config('shipping.tracking.sync_interval', 3600);

        return ShippingOwnerScope::applyToOwnedQuery(Shipment::query())
            ->whereNotNull('tracking_number')
            ->whereNotIn('status', [
                ShipmentStatusState::normalize(Delivered::class),
                ShipmentStatusState::normalize(Cancelled::class),
                ShipmentStatusState::normalize(ReturnToSender::class),
            ])
            ->where('created_at', '>', now()->subDays($maxAge))
            ->where(function ($query) use ($syncInterval): void {
                $query->whereNull('last_tracking_sync')
                    ->orWhere('last_tracking_sync', '<', now()->subSeconds($syncInterval));
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Process tracking events and create records for new ones.
     *
     * @param  Collection<int, TrackingEventData>  $events
     * @return Collection<int, ShipmentEvent>
     */
    protected function processTrackingEvents(Shipment $shipment, Collection $events): Collection
    {
        $newEvents = collect();
        $mapper = $this->statusMappers[$shipment->carrier_code] ?? null;

        foreach ($events as $eventData) {
            // Check if event already exists
            $exists = $shipment->events()
                ->where('carrier_event_code', $eventData->code)
                ->where('occurred_at', $eventData->timestamp)
                ->exists();

            if (! $exists) {
                $normalizedStatus = $eventData->normalizedStatus
                    ?? ($mapper ? $mapper->map($eventData->code) : TrackingStatus::InTransit);

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

                $newEvents->push($event);
            }
        }

        return $newEvents;
    }

    /**
     * Update shipment status based on latest tracking event.
     */
    protected function updateShipmentStatus(Shipment $shipment): void
    {
        $latestEvent = $shipment->events()->latest('occurred_at')->first();

        if ($latestEvent === null) {
            return;
        }

        $normalizedStatus = $this->mapTrackingToShipmentStatus($latestEvent->normalized_status);
        $statusClass = ShipmentStatusState::resolveStateClass($normalizedStatus) ?? null;

        if ($statusClass === null || ! is_subclass_of($statusClass, ShipmentStatusState::class)) {
            return;
        }

        if (! $shipment->status->equals($statusClass)) {
            $oldStatus = $shipment->status;
            $shipment->update(['status' => $statusClass]);

            if ($shipment->status->equals(Delivered::class)) {
                $shipment->update(['delivered_at' => $latestEvent->occurred_at]);
                event(new ShipmentDelivered($shipment));
            }

            event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));
        }
    }

    /**
     * Map tracking status to shipment status.
     */
    protected function mapTrackingToShipmentStatus(TrackingStatus $trackingStatus): string
    {
        return match ($trackingStatus->getCategory()) {
            'pre_shipment' => ShipmentStatusState::normalize(AwaitingPickup::class),
            'in_transit' => ShipmentStatusState::normalize(InTransit::class),
            'out_for_delivery' => ShipmentStatusState::normalize(OutForDelivery::class),
            'delivered' => ShipmentStatusState::normalize(Delivered::class),
            'exception' => ShipmentStatusState::normalize(ExceptionStatus::class),
            'return' => ShipmentStatusState::normalize(ReturnToSender::class),
            default => ShipmentStatusState::normalize(InTransit::class),
        };
    }
}
