<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Shipping\Enums\ShipmentStatus as ShipmentStatusEnum;
use AIArmada\Shipping\States\ShipmentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Spatie\ModelStates\HasStates;

/**
 * @property string $id
 * @property string $ulid
 * @property string $reference
 * @property string $carrier_code
 * @property string|null $service_code
 * @property string|null $tracking_number
 * @property string|null $carrier_reference
 * @property ShipmentStatus $status
 * @property array $origin_address
 * @property array $destination_address
 * @property int $package_count
 * @property int $total_weight
 * @property int $declared_value
 * @property string $currency
 * @property int $shipping_cost
 * @property int $insurance_cost
 * @property int|null $cod_amount
 * @property string|null $label_url
 * @property string|null $label_format
 * @property CarbonInterface|null $shipped_at
 * @property CarbonInterface|null $estimated_delivery_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $last_tracking_sync
 * @property array|null $metadata
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection<int, ShipmentItem> $items
 * @property-read Collection<int, ShipmentEvent> $events
 * @property-read Collection<int, ShipmentLabel> $labels
 */
class Shipment extends Model
{
    use FormatsMoney;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasStates;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'shipping.features.owner';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'owner_id',
        'owner_type',
        'shippable_id',
        'shippable_type',
        'reference',
        'carrier_code',
        'service_code',
        'tracking_number',
        'carrier_reference',
        'status',
        'origin_address',
        'destination_address',
        'package_count',
        'total_weight',
        'declared_value',
        'currency',
        'shipping_cost',
        'insurance_cost',
        'cod_amount',
        'label_url',
        'label_format',
        'shipped_at',
        'estimated_delivery_at',
        'delivered_at',
        'last_tracking_sync',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'package_count' => 1,
        'total_weight' => 0,
        'declared_value' => 0,
        'currency' => 'MYR',
        'shipping_cost' => 0,
        'insurance_cost' => 0,
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.shipments', 'shipments');
    }

    // ─────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────

    /**
     * @return HasMany<ShipmentItem, Shipment>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * @return HasMany<ShipmentEvent, Shipment>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at', 'desc');
    }

    /**
     * @return HasMany<ShipmentLabel, Shipment>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(ShipmentLabel::class);
    }

    /**
     * Polymorphic relationship to the "shippable" (order, cart, etc.)
     */
    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isInTransit(): bool
    {
        return $this->status->isInTransit();
    }

    public function isDelivered(): bool
    {
        return $this->status->isDelivered();
    }

    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canTransitionTo(string | ShipmentStatus | ShipmentStatusEnum $newStatus): bool
    {
        return $this->status->canTransitionTo(ShipmentStatus::normalize($newStatus));
    }

    public function getLatestEvent(): ?ShipmentEvent
    {
        return $this->events()->latest('occurred_at')->first();
    }

    public function isCashOnDelivery(): bool
    {
        return $this->cod_amount !== null && $this->cod_amount > 0;
    }

    // ─────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────

    public function getFormattedShippingCost(): string
    {
        return $this->formatMoney($this->shipping_cost);
    }

    public function getFormattedDeclaredValue(): string
    {
        return $this->formatMoney($this->declared_value);
    }

    public function getFormattedInsuranceCost(): string
    {
        return $this->formatMoney($this->insurance_cost);
    }

    public function getFormattedCodAmount(): string
    {
        return $this->formatMoney($this->cod_amount ?? 0);
    }

    public function getTotalWeightKg(): float
    {
        return $this->total_weight / 1000;
    }

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment): void {
            if (empty($shipment->ulid)) {
                $shipment->ulid = (string) Str::ulid();
            }

            if (empty($shipment->currency)) {
                $shipment->currency = (string) config('shipping.defaults.currency', 'MYR');
            }
        });

        static::deleting(function (Shipment $shipment): void {
            $shipment->items()->delete();
            $shipment->events()->delete();
            $shipment->labels()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'origin_address' => 'array',
            'destination_address' => 'array',
            'package_count' => 'integer',
            'total_weight' => 'integer',
            'declared_value' => 'integer',
            'shipping_cost' => 'integer',
            'insurance_cost' => 'integer',
            'cod_amount' => 'integer',
            'shipped_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_tracking_sync' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
