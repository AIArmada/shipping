<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Models;

use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShipmentOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'shipment_id',
        'operation_type',
        'status',
        'outcome_type',
        'reference',
        'carrier_reference',
        'tracking_number',
        'error_message',
        'carrier_result',
        'operation_started_at',
        'operation_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'carrier_result' => 'array',
            'operation_started_at' => 'immutable_datetime',
            'operation_completed_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return config('shipping.database.tables.shipment_operations', 'shipment_operations');
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function status(): ShipmentOperationStatus
    {
        return ShipmentOperationStatus::tryFrom((string) $this->getAttribute('status')) ?? ShipmentOperationStatus::Unknown;
    }
}
