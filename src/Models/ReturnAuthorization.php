<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Shipping\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $rma_number
 * @property string|null $original_shipment_id
 * @property string|null $order_reference
 * @property string|null $customer_id
 * @property string $status
 * @property string $type
 * @property string $reason
 * @property string|null $reason_details
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $received_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Shipment|null $originalShipment
 * @property-read Shipment|null $returnShipment
 * @property-read Collection<int, ReturnAuthorizationItem> $items
 */
class ReturnAuthorization extends Model
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
        'rma_number',
        'original_shipment_id',
        'order_reference',
        'customer_id',
        'status',
        'type',
        'reason',
        'reason_details',
        'approved_by',
        'approved_at',
        'received_at',
        'completed_at',
        'expires_at',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    public static function generateRmaNumber(): string
    {
        return 'RMA-' . Str::upper((string) Str::ulid());
    }

    public function getTable(): string
    {
        return config('shipping.database.tables.return_authorizations', 'return_authorizations');
    }

    // ─────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Shipment, ReturnAuthorization>
     */
    public function originalShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'original_shipment_id');
    }

    /**
     * @return HasOne<Shipment, ReturnAuthorization>
     */
    public function returnShipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'shippable_id')
            ->where('shippable_type', static::class);
    }

    /**
     * @return HasMany<ReturnAuthorizationItem, ReturnAuthorization>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnAuthorizationItem::class);
    }

    // ─────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────

    /**
     * @param  Builder<ReturnAuthorization>  $query
     * @return Builder<ReturnAuthorization>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<ReturnAuthorization>  $query
     * @return Builder<ReturnAuthorization>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast() && $this->isPending();
    }

    public function getReasonEnum(): ?ReturnReason
    {
        return ReturnReason::tryFrom($this->reason);
    }

    protected static function booted(): void
    {
        static::creating(function (ReturnAuthorization $rma): void {
            if (empty($rma->rma_number)) {
                $rma->rma_number = static::generateRmaNumber();
            }
        });

        static::deleting(function (ReturnAuthorization $rma): void {
            $rma->items()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
