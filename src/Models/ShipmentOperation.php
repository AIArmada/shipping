<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use Carbon\CarbonImmutable;
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

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public static function recordStart(string $shipmentId, string $operationType, ?string $reference = null): self
    {
        $existing = self::query()
            ->where('shipment_id', $shipmentId)
            ->where('operation_type', $operationType)
            ->where('status', ShipmentOperationStatus::Pending->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return self::create([
            'shipment_id' => $shipmentId,
            'operation_type' => $operationType,
            'status' => ShipmentOperationStatus::Pending->value,
            'reference' => $reference,
            'operation_started_at' => CarbonImmutable::now(),
        ]);
    }

    public function complete(CarrierOperationResult $result): void
    {
        $status = match (true) {
            $result->success => ShipmentOperationStatus::Succeeded,
            $result->outcomeType === 'unknown' => ShipmentOperationStatus::Unknown,
            default => ShipmentOperationStatus::Failed,
        };

        $this->update([
            'status' => $status->value,
            'outcome_type' => $result->outcomeType,
            'carrier_reference' => $result->carrierReference,
            'tracking_number' => $result->trackingNumber,
            'error_message' => $result->error,
            'carrier_result' => $result->toArray(),
            'operation_completed_at' => CarbonImmutable::now(),
        ]);
    }

    public function status(): ShipmentOperationStatus
    {
        return ShipmentOperationStatus::tryFrom((string) $this->getAttribute('status')) ?? ShipmentOperationStatus::Unknown;
    }
}
