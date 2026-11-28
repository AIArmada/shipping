<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use DateTimeInterface;

/**
 * Tracking data from a carrier.
 */
final readonly class TrackingData
{
    /**
     * @param  list<TrackingEventData>  $events
     */
    public function __construct(
        public string $trackingNumber,
        public TrackingStatus $status,
        public array $events,
        public ?string $carrier = null,
        public ?DateTimeInterface $estimatedDelivery = null,
        public ?DateTimeInterface $deliveredAt = null,
        public ?string $signedBy = null,
        public ?string $currentLocation = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status->getCategory() === 'delivered';
    }

    public function hasEvents(): bool
    {
        return $this->events !== [];
    }

    public function getLatestEvent(): ?TrackingEventData
    {
        $latest = null;

        foreach ($this->events as $event) {
            if ($latest === null || $event->timestamp > $latest->timestamp) {
                $latest = $event;
            }
        }

        return $latest;
    }
}
