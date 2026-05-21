<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Shipping\Data\AddressData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property array|null $countries
 * @property array|null $states
 * @property array|null $postcode_ranges
 * @property float|null $center_lat
 * @property float|null $center_lng
 * @property int|null $radius_km
 * @property int $priority
 * @property bool $is_default
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ShippingRate> $rates
 */
class ShippingZone extends Model
{
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'shipping.features.owner';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'owner_id',
        'owner_type',
        'name',
        'code',
        'type',
        'countries',
        'states',
        'postcode_ranges',
        'center_lat',
        'center_lng',
        'radius_km',
        'priority',
        'is_default',
        'active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 0,
        'is_default' => false,
        'active' => true,
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.shipping_zones', 'shipping_zones');
    }

    protected static function booted(): void
    {
        static::deleting(function (ShippingZone $zone): void {
            $zone->rates()->delete();
        });
    }

    // ─────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────

    /**
     * @return HasMany<ShippingRate, ShippingZone>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'zone_id');
    }

    // ─────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────

    /**
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('priority');
    }

    // ─────────────────────────────────────────────────────────────
    // MATCHING LOGIC
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if an address matches this zone.
     */
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

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'states' => 'array',
            'postcode_ranges' => 'array',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'radius_km' => 'integer',
            'priority' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected function matchesCountry(AddressData $address): bool
    {
        if ($this->countries === null || empty($this->countries)) {
            return false;
        }

        return in_array(mb_strtoupper($address->country), array_map('strtoupper', $this->countries), true);
    }

    protected function matchesState(AddressData $address): bool
    {
        // First check country
        if (! $this->matchesCountry($address)) {
            return false;
        }

        if ($this->states === null || empty($this->states)) {
            return true; // If no states specified, match entire country
        }

        $addressState = mb_strtolower($address->state ?? '');

        foreach ($this->states as $state) {
            if (str_contains($addressState, mb_strtolower($state))) {
                return true;
            }
        }

        return false;
    }

    protected function matchesPostcode(AddressData $address): bool
    {
        if ($this->postcode_ranges === null || empty($this->postcode_ranges)) {
            return false;
        }

        $postcode = $address->postcode;

        if (empty($postcode)) {
            return false;
        }

        foreach ($this->postcode_ranges as $range) {
            $from = $range['from'] ?? '';
            $to = $range['to'] ?? $from;

            if ($postcode >= $from && $postcode <= $to) {
                return true;
            }
        }

        return false;
    }

    protected function matchesRadius(AddressData $address): bool
    {
        if ($this->center_lat === null || $this->center_lng === null || $this->radius_km === null) {
            return false;
        }

        if ($address->latitude === null || $address->longitude === null) {
            return false; // Cannot calculate distance without coordinates
        }

        $distance = $this->calculateDistance(
            $this->center_lat,
            $this->center_lng,
            $address->latitude,
            $address->longitude
        );

        return $distance <= $this->radius_km;
    }

    /**
     * Calculate distance in km using Haversine formula.
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
