<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use DateTimeInterface;

/**
 * A single tracking event.
 */
final readonly class TrackingEventData
{
    public function __construct(
        public string $code,
        public string $description,
        public DateTimeInterface $timestamp,
        public ?TrackingStatus $normalizedStatus = null,
        public ?string $location = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?array $raw = null,
    ) {}

    public function getFormattedLocation(): string
    {
        return implode(', ', array_filter([
            $this->city,
            $this->state,
            $this->country,
        ]));
    }
}
