<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Shipping\Enums\ReturnReason;
use AIArmada\Shipping\Features\Returns\Actions\PackageReturns;
use AIArmada\Shipping\Features\Returns\Persistence\DeleteReturnItems;
use AIArmada\Shipping\Features\Returns\States\ReturnAuthorizationStatus;
use AIArmada\Shipping\Features\Returns\States\RmaApproved;
use AIArmada\Shipping\Features\Returns\States\RmaCancelled;
use AIArmada\Shipping\Features\Returns\States\RmaCompleted;
use AIArmada\Shipping\Features\Returns\States\RmaPending;
use AIArmada\Shipping\Features\Returns\States\RmaReceived;
use AIArmada\Shipping\Features\Shipments\Api\ReadReturnShipment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\ModelStates\HasStates;

/**
 * @property string $id
 * @property string $rma_number
 * @property string|null $order_item_id
 * @property int|null $package_unit_ordinal
 * @property string|null $original_shipment_id
 * @property string|null $order_reference
 * @property string|null $customer_id
 * @property ReturnAuthorizationStatus $status
 * @property string $type
 * @property string $reason
 * @property string|null $reason_details
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expires_at
 * @property array|null $metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ReturnAuthorizationItem> $items
 */
class ReturnAuthorization extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasStates;
    use HasUuids;
    use LogsCommerceActivity;

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
        'rejected_by',
        'rejected_at',
        'received_at',
        'completed_at',
        'cancelled_at',
        'expires_at',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [];

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
     * @return HasMany<ReturnAuthorizationItem, ReturnAuthorization>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnAuthorizationItem::class);
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }

    public function isRejected(): bool
    {
        return $this->status->isRejected();
    }

    public function isReceived(): bool
    {
        return $this->status instanceof RmaReceived;
    }

    public function isCompleted(): bool
    {
        return $this->status instanceof RmaCompleted;
    }

    public function isCancelled(): bool
    {
        return $this->status instanceof RmaCancelled;
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

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereState('status', RmaPending::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereState('status', RmaApproved::class);
    }

    public function isPackageReturn(): bool
    {
        return $this->order_item_id !== null || $this->package_unit_ordinal !== null;
    }

    protected static function booted(): void
    {
        static::saving(function (ReturnAuthorization $rma): void {
            if (! $rma->isPackageReturn() && $rma->getRawOriginal('package_unit_ordinal') === null) {
                return;
            }

            if ($rma->exists && $rma->isDirty(['order_item_id', 'package_unit_ordinal', 'original_shipment_id', 'type'])) {
                throw new DomainException('The original package unit is immutable.');
            }

            if ($rma->exists && $rma->isDirty('status')) {
                $current = $rma->newQuery()->findOrFail($rma->id)->status;
                if (! $current->equals($rma->getOriginal('status')) || ! $current->canTransitionTo($rma->status)) {
                    throw new DomainException('Reload the package return before changing its status.');
                }
            }

            if ($rma->isDirty('status') || ! $rma->exists) {
                app(PackageReturns::class)->assertStatus($rma);
            }
        });

        static::creating(function (ReturnAuthorization $rma): void {
            if (empty($rma->rma_number)) {
                $rma->rma_number = static::generateRmaNumber();
            }
        });

        static::deleting(function (ReturnAuthorization $rma): void {
            if ($rma->isPackageReturn() && ($rma->isReceived() || $rma->isCompleted())) {
                throw new DomainException('A received package return cannot be deleted.');
            }

            if (app(ReadReturnShipment::class)->linkedShipmentId($rma->getMorphClass(), (string) $rma->getKey()) !== null) {
                throw new DomainException('A return authorization with a return shipment cannot be deleted.');
            }

            app(DeleteReturnItems::class)->handle($rma);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ReturnAuthorizationStatus::class,
            'package_unit_ordinal' => 'integer',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
