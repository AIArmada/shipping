<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use AIArmada\Shipping\Enums\TrackingStatus;
use DateTimeInterface;
use Spatie\LaravelData\Data;

/**
 * A single tracking event.
 */
class TrackingEventData extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly DateTimeInterface $timestamp,
        public readonly ?TrackingStatus $normalizedStatus = null,
        public readonly ?string $location = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?array $raw = null,
    ) {}

    public function getFormattedLocation(): string
    {
        return collect([
            $this->city,
            $this->state,
            $this->country,
        ])->filter()->implode(', ');
    }
}
