<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

final readonly class ZoneCandidateData
{
    /**
     * @param  array<string>|null  $countries
     * @param  array<string>|null  $states
     * @param  array<array{from?: string, to?: string}>|null  $postcodeRanges
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?array $countries,
        public ?array $states,
        public ?array $postcodeRanges,
        public ?float $centerLat,
        public ?float $centerLng,
        public ?int $radiusKm,
        public int $priority,
        public bool $isDefault,
        public bool $active,
    ) {}

    public function matchesAddress(AddressData $address): bool
    {
        return match ($this->type) {
            'country' => $this->matchesCountry($address),
            'state' => $this->matchesState($address),
            'postcode' => $this->matchesPostcode($address),
            'radius' => $this->matchesRadius($address),
            default => false,
        };
    }

    private function matchesCountry(AddressData $address): bool
    {
        if ($this->countries === null || empty($this->countries)) {
            return false;
        }

        return in_array(mb_strtoupper($address->country), array_map('strtoupper', $this->countries), true);
    }

    private function matchesState(AddressData $address): bool
    {
        // First check country
        if (! $this->matchesCountry($address)) {
            return false;
        }

        if ($this->states === null || empty($this->states)) {
            return true; // If no states specified, match entire country
        }

        $addressState = mb_strtolower(mb_trim($address->state ?? ''));

        foreach ($this->states as $state) {
            if ($addressState !== '' && $addressState === mb_strtolower(mb_trim($state))) {
                return true;
            }
        }

        return false;
    }

    private function matchesPostcode(AddressData $address): bool
    {
        if ($this->postcodeRanges === null || empty($this->postcodeRanges)) {
            return false;
        }

        $postcode = $address->postcode;

        if (empty($postcode)) {
            return false;
        }

        foreach ($this->postcodeRanges as $range) {
            $from = (string) ($range['from'] ?? '');
            $to = (string) ($range['to'] ?? $from);

            if ($this->postcodeInRange($postcode, $from, $to)) {
                return true;
            }
        }

        return false;
    }

    private function postcodeInRange(string $postcode, string $from, string $to): bool
    {
        // All-digit postcodes compare numerically so that '50000' does not match
        // a '1000'-'9999' range the way lexicographic comparison would allow.
        if (ctype_digit($postcode) && ctype_digit($from) && ctype_digit($to)) {
            return (int) $postcode >= (int) $from && (int) $postcode <= (int) $to;
        }

        return $postcode >= $from && $postcode <= $to;
    }

    private function matchesRadius(AddressData $address): bool
    {
        if ($this->centerLat === null || $this->centerLng === null || $this->radiusKm === null) {
            return false;
        }

        if ($address->latitude === null || $address->longitude === null) {
            return false; // Cannot calculate distance without coordinates
        }

        $distance = $this->calculateDistance(
            $this->centerLat,
            $this->centerLng,
            $address->latitude,
            $address->longitude
        );

        return $distance <= $this->radiusKm;
    }

    /**
     * Calculate distance in km using Haversine formula.
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $a = max(0.0, min(1.0, $a));
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
