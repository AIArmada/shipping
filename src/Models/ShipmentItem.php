<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string|null $order_item_id
 * @property int|null $package_unit_ordinal
 * @property int|null $package_component_index
 * @property string|null $shippable_item_id
 * @property string|null $shippable_item_type
 * @property string|null $sku
 * @property string $name
 * @property string|null $description
 * @property int $quantity
 * @property int $weight
 * @property int $declared_value
 * @property string|null $hs_code
 * @property string|null $origin_country
 * @property array|null $metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Shipment $shipment
 */
class ShipmentItem extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasUuids;
    use LogsCommerceActivity;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'shipment_id',
        'shippable_item_id',
        'shippable_item_type',
        'order_item_id',
        'package_unit_ordinal',
        'package_component_index',
        'sku',
        'name',
        'description',
        'quantity',
        'weight',
        'declared_value',
        'hs_code',
        'origin_country',
        'metadata',
    ];

    public function getAuditInclude(): array
    {
        return [
            'shipment_id',
            'shippable_item_id',
            'shippable_item_type',
            'sku',
            'name',
            'description',
            'quantity',
            'weight',
            'declared_value',
            'hs_code',
            'origin_country',
            'metadata',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'weight' => 0,
        'declared_value' => 0,
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.shipment_items', 'shipment_items');
    }

    /**
     * @return BelongsTo<Shipment, ShipmentItem>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Polymorphic relationship to the original item.
     */
    public function shippableItem(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTotalWeight(): int
    {
        return $this->weight * $this->quantity;
    }

    public function getTotalValue(): int
    {
        return $this->declared_value * $this->quantity;
    }

    protected static function booted(): void
    {
        static::updating(function (ShipmentItem $item): void {
            if ($item->getRawOriginal('package_unit_ordinal') !== null
                && $item->isDirty(['shipment_id', 'order_item_id', 'package_unit_ordinal', 'package_component_index', 'shippable_item_id', 'shippable_item_type', 'quantity'])) {
                throw new DomainException('A shipment component cannot be moved out of its package unit.');
            }
        });

        static::deleting(function (ShipmentItem $item): void {
            if ($item->package_unit_ordinal !== null) {
                throw new DomainException('A shipment component cannot be removed from its package unit.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'package_unit_ordinal' => 'integer',
            'package_component_index' => 'integer',
            'weight' => 'integer',
            'declared_value' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected function getActivityLogName(): string
    {
        return 'shipping';
    }
}
