<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeKey;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\ZoneCandidateData;
use AIArmada\Shipping\Features\Rates\Persistence\DeleteZoneRates;
use AIArmada\Shipping\Features\Rates\Queries\ShippingZoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

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
class ShippingZone extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasOwnerScopeKey;
    use HasUuids;
    use LogsCommerceActivity;

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

    public function getAuditInclude(): array
    {
        return [
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
    }

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
        static::saved(static function (): void {
            self::clearResolverCache();
        });

        static::deleted(static function (): void {
            self::clearResolverCache();
        });

        static::deleting(function (ShippingZone $zone): void {
            app(DeleteZoneRates::class)->handle($zone);
        });
    }

    private static function clearResolverCache(): void
    {
        if (app()->bound(ShippingZoneResolver::class)) {
            app(ShippingZoneResolver::class)->clearCache();
        }
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
        return $this->toCandidate()->matchesAddress($address);
    }

    public function toCandidate(): ZoneCandidateData
    {
        return new ZoneCandidateData(
            id: $this->id,
            type: $this->type,
            countries: $this->countries,
            states: $this->states,
            postcodeRanges: $this->postcode_ranges,
            centerLat: $this->center_lat,
            centerLng: $this->center_lng,
            radiusKm: $this->radius_km,
            priority: $this->priority,
            isDefault: $this->is_default,
            active: $this->active,
        );
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

    protected function getActivityLogName(): string
    {
        return 'shipping';
    }
}
