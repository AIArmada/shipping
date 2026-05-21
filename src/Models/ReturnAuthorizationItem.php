<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $return_authorization_id
 * @property string|null $sku
 * @property string $name
 * @property int $quantity_requested
 * @property int $quantity_approved
 * @property int $quantity_received
 * @property string|null $reason
 * @property string|null $condition
 * @property array|null $metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ReturnAuthorization $returnAuthorization
 */
class ReturnAuthorizationItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'return_authorization_id',
        'original_item_id',
        'original_item_type',
        'sku',
        'name',
        'quantity_requested',
        'quantity_approved',
        'quantity_received',
        'reason',
        'condition',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_requested' => 1,
        'quantity_approved' => 0,
        'quantity_received' => 0,
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.return_authorization_items', 'return_authorization_items');
    }

    /**
     * @return BelongsTo<ReturnAuthorization, ReturnAuthorizationItem>
     */
    public function returnAuthorization(): BelongsTo
    {
        return $this->belongsTo(ReturnAuthorization::class);
    }

    /**
     * Polymorphic relationship to the original item.
     */
    public function originalItem(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFullyApproved(): bool
    {
        return $this->quantity_approved >= $this->quantity_requested;
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_approved;
    }

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'quantity_received' => 'integer',
            'metadata' => 'array',
        ];
    }
}
