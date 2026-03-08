<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string|null $sku
 * @property string $name
 * @property string|null $description
 * @property int $quantity
 * @property int $weight
 * @property int $declared_value
 * @property string|null $hs_code
 * @property string|null $origin_country
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Shipment $shipment
 */
class ShipmentItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
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

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'weight' => 'integer',
            'declared_value' => 'integer',
            'metadata' => 'array',
        ];
    }
}
