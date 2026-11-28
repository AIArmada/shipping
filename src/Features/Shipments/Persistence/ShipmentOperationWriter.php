<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Persistence;

use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class ShipmentOperationWriter
{
    public function start(Shipment $shipment, string $operationType, ?string $reference = null): ShipmentOperation
    {
        if (! $shipment->exists) {
            throw new InvalidArgumentException('Shipment must be persisted before recording an operation.');
        }

        // Check-then-insert runs inside a locked transaction so concurrent
        // callers serialize instead of double-creating pending rows. A unique
        // index is intentionally not used: repeat operations legitimately
        // produce multiple completed rows per (shipment, operation type).
        return DB::transaction(function () use ($shipment, $operationType, $reference): ShipmentOperation {
            $lockedShipment = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($operationType, ['create', 'cancel'], true)) {
                $opposite = $operationType === 'create' ? 'cancel' : 'create';
                $blockingStatuses = [ShipmentOperationStatus::Pending->value, ShipmentOperationStatus::Unknown->value];

                if ($operationType === 'create' || $lockedShipment->tracking_number === null) {
                    $blockingStatuses[] = ShipmentOperationStatus::Succeeded->value;
                }

                if ($lockedShipment->operations()->where('operation_type', $opposite)
                    ->whereIn('status', $blockingStatuses)->exists()) {
                    throw new RuntimeException('Reconcile the conflicting carrier operation first.');
                }
            }

            $existing = ShipmentOperation::query()
                ->where('shipment_id', $shipment->getKey())
                ->where('operation_type', $operationType)
                ->whereIn('status', [ShipmentOperationStatus::Pending->value, ShipmentOperationStatus::Unknown->value, ShipmentOperationStatus::Succeeded->value])
                ->orderByDesc('operation_started_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ShipmentOperation::query()->create([
                'shipment_id' => $shipment->getKey(),
                'operation_type' => $operationType,
                'status' => ShipmentOperationStatus::Pending->value,
                'reference' => $reference,
                'operation_started_at' => CarbonImmutable::now(),
            ]);
        });
    }

    public function complete(ShipmentOperation $operation, CarrierOperationResult $result): void
    {
        $status = match (true) {
            $result->success || $result->alreadyApplied => ShipmentOperationStatus::Succeeded,
            $result->outcomeType === 'unknown' => ShipmentOperationStatus::Unknown,
            default => ShipmentOperationStatus::Failed,
        };

        $operation->update([
            'status' => $status->value,
            'outcome_type' => $result->outcomeType,
            'carrier_reference' => $result->carrierReference,
            'tracking_number' => $result->trackingNumber,
            'error_message' => $result->error,
            'carrier_result' => $result->toArray(),
            'operation_completed_at' => CarbonImmutable::now(),
        ]);
    }
}
