<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use AIArmada\Shipping\Enums\TrackingStatus;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Tracking data from a carrier.
 */
class TrackingData extends Data
{
    /**
     * @param  Collection<int, TrackingEventData>  $events
     */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly TrackingStatus $status,
        public readonly Collection $events,
        public readonly ?string $carrier = null,
        public readonly ?DateTimeInterface $estimatedDelivery = null,
        public readonly ?DateTimeInterface $deliveredAt = null,
        public readonly ?string $signedBy = null,
        public readonly ?string $currentLocation = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status->isTerminal() && $this->status === TrackingStatus::Delivered;
    }

    public function hasEvents(): bool
    {
        return $this->events->isNotEmpty();
    }

    public function getLatestEvent(): ?TrackingEventData
    {
        return $this->events->first();
    }
}
