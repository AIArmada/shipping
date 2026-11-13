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
 * @property string $return_authorization_id
 * @property string|null $original_item_type
 * @property string|null $original_item_id
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
class ReturnAuthorizationItem extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasUuids;
    use LogsCommerceActivity;

    public const array CONDITIONS = ['unopened', 'opened', 'damaged', 'defective', 'mixed'];

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

    protected static function booted(): void
    {
        static::saving(function (ReturnAuthorizationItem $item): void {
            if ($item->exists && $item->isDirty('return_authorization_id')) {
                throw new DomainException('A return component cannot move to another authorization.');
            }

            $rma = $item->returnAuthorization()->firstOrFail();
            if ($rma->isPackageReturn()) {
                if ($rma->isReceived() || $rma->isCompleted()
                    || ($item->exists && $item->isDirty(['return_authorization_id', 'original_item_id', 'original_item_type', 'quantity_requested']))) {
                    throw new DomainException('Package return components are fixed by the original shipment.');
                }

                return;
            }

            $shipmentItem = new ShipmentItem;
            if ($item->original_item_type === $shipmentItem->getMorphClass()
                && $item->original_item_id !== null
                && ShipmentItem::query()->whereKey($item->original_item_id)->whereNotNull('package_unit_ordinal')
                    ->whereHas('shipment')->exists()) {
                throw new DomainException('Package components must be returned as a complete package unit.');
            }
        });

        static::deleting(function (ReturnAuthorizationItem $item): void {
            if ($item->returnAuthorization()->firstOrFail()->isPackageReturn()) {
                throw new DomainException('Package return components cannot be removed.');
            }
        });
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
