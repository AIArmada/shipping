<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Shipping\Contracts\StatusMapperInterface;
use AIArmada\Shipping\Data\DriverCapability;
use AIArmada\Shipping\Data\TrackingEventData;
use AIArmada\Shipping\Data\TrackingStatus;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentDelivered;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Features\Shipments\Events\TrackingUpdated;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentEvent;
use AIArmada\Shipping\Features\Shipments\States\AwaitingPickup;
use AIArmada\Shipping\Features\Shipments\States\Cancelled;
use AIArmada\Shipping\Features\Shipments\States\Delivered;
use AIArmada\Shipping\Features\Shipments\States\ExceptionStatus;
use AIArmada\Shipping\Features\Shipments\States\InTransit;
use AIArmada\Shipping\Features\Shipments\States\OutForDelivery;
use AIArmada\Shipping\Features\Shipments\States\ReturnToSender;
use AIArmada\Shipping\Features\Shipments\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\ShippingManager;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($shipment, $trackingData): Shipment {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());

            if ($locked->tracking_number !== $shipment->tracking_number || $locked->carrier_code !== $shipment->carrier_code) {
                return $locked;
            }

            $newEvents = $this->processTrackingEvents($locked, $trackingData->events);

            $locked->update(['last_tracking_sync' => CarbonImmutable::now()]);

            if ($newEvents->isNotEmpty()) {
                $this->updateShipmentStatus($locked);
                event(new TrackingUpdated($locked, $newEvents));
            }

            return $locked->refresh();
        });
    }

    /**
     * Sync tracking for multiple shipments.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return Collection<int, array{id: string, success: bool, error?: string}>
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
     * In non-request contexts (commands/jobs), pass $owner explicitly per tenant.
     * When $owner is null and owner mode is enabled, only global shipments are returned.
     *
     * @return Collection<int, Shipment>
     */
    public function getShipmentsNeedingUpdate(int $limit = 100, ?Model $owner = null): Collection
    {
        $maxAge = config('shipping.tracking.max_tracking_age', 30);
        $syncInterval = config('shipping.tracking.sync_interval', 3600);

        $query = Shipment::query();

        if (config('shipping.features.owner.enabled', false)) {
            $query->forOwner(
                $owner,
                includeGlobal: (bool) config('shipping.features.owner.include_global', false),
            );
        }

        return $query
            ->whereNotNull('tracking_number')
            ->whereNotIn('status', [
                ShipmentStatusState::normalize(Delivered::class),
                ShipmentStatusState::normalize(Cancelled::class),
                ShipmentStatusState::normalize(ReturnToSender::class),
            ])
            ->where('created_at', '>', CarbonImmutable::now()->subDays($maxAge))
            ->where(function ($query) use ($syncInterval): void {
                $query->whereNull('last_tracking_sync')
                    ->orWhere('last_tracking_sync', '<', CarbonImmutable::now()->subSeconds($syncInterval));
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Process tracking events and create records for new ones.
     *
     * @param  list<TrackingEventData>  $events
     * @return Collection<int, ShipmentEvent>
     */
    protected function processTrackingEvents(Shipment $shipment, array $events): Collection
    {
        $newEvents = collect();
        $mapper = $this->statusMappers[$shipment->carrier_code] ?? null;

        if ($events === []) {
            return $newEvents;
        }

        // Fetch existing (code, occurred_at) pairs once per shipment instead of
        // issuing one exists() query per event.
        /** @var array<string, true> $existing */
        $existing = [];

        foreach ($shipment->events()
            ->whereIn('carrier_event_code', array_values(array_unique(array_map(static fn (TrackingEventData $eventData): string => $eventData->code, $events))))
            ->get(['carrier_event_code', 'occurred_at']) as $storedEvent) {
            $existing[$this->trackingEventKey($storedEvent->carrier_event_code, $storedEvent->occurred_at)] = true;
        }

        foreach ($events as $eventData) {
            $key = $this->trackingEventKey($eventData->code, $eventData->timestamp);

            if (isset($existing[$key])) {
                continue;
            }

            $existing[$key] = true;

            $normalizedStatus = $eventData->normalizedStatus;

            if ($normalizedStatus === null) {
                $mapper ??= $this->shippingManager->getStatusMapper($shipment->carrier_code);
                $normalizedStatus = $mapper?->map($eventData->code) ?? TrackingStatus::InTransit;
            }

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

        return $newEvents;
    }

    private function trackingEventKey(?string $code, mixed $occurredAt): string
    {
        $timestamp = $occurredAt instanceof DateTimeInterface
            ? CarbonImmutable::parse($occurredAt->format(DateTimeInterface::ATOM))
            : CarbonImmutable::parse((string) $occurredAt);

        return ($code ?? '') . '|' . $timestamp->getTimestamp();
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

        if (! $shipment->status->equals($statusClass) && $shipment->status->canTransitionTo($statusClass)) {
            $oldStatus = $shipment->status;
            $shipment->status->transitionTo($statusClass);

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
