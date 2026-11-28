<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Exceptions\ShipmentNotCancellableException;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentCancelled;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Persistence\ShipmentOperationWriter;
use AIArmada\Shipping\Features\Shipments\States\Cancelled;
use AIArmada\Shipping\ShippingManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class CancelShipment
{
    use AsAction;

    public function __construct(
        protected readonly ShippingManager $shippingManager,
    ) {}

    public function handle(Shipment $shipment, ?string $reason = null): Shipment
    {
        $lockKey = "shipment:{$shipment->getKey()}:ship";

        return Cache::lock($lockKey, 30)->block(30, function () use ($shipment, $reason): Shipment {
            $shipment->refresh();
            if (! $shipment->isCancellable()) {
                throw new ShipmentNotCancellableException($shipment->reference, $shipment->status->getValue());
            }

            $unresolvedCreation = $shipment->operations()->where('operation_type', 'create')
                ->whereIn('status', [ShipmentOperationStatus::Pending->value, ShipmentOperationStatus::Unknown->value])
                ->exists();

            if ($unresolvedCreation || ($shipment->tracking_number === null
                && $shipment->operations()->where('operation_type', 'create')
                    ->where('status', ShipmentOperationStatus::Succeeded->value)->exists())) {
                throw new RuntimeException('Reconcile the carrier operation before canceling this shipment.');
            }

            $previous = $shipment->operations()->where('operation_type', 'cancel')
                ->orderByDesc('operation_started_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (in_array($previous?->status(), [ShipmentOperationStatus::Pending, ShipmentOperationStatus::Unknown], true)) {
                throw new RuntimeException('Reconcile the existing cancellation before retrying.');
            }

            if ($previous?->status() !== ShipmentOperationStatus::Succeeded) {
                $driver = $shipment->tracking_number !== null
                    ? $this->shippingManager->driver($shipment->carrier_code)
                    : null;

                $writer = app(ShipmentOperationWriter::class);
                $operation = $writer->start($shipment, 'cancel', $shipment->carrier_reference);

                if (! $operation->wasRecentlyCreated) {
                    throw new RuntimeException('Another carrier cancellation is already in progress or completed.');
                }

                try {
                    $result = $driver === null
                        ? CarrierOperationResult::succeeded()
                        : $driver->cancelShipment($shipment->tracking_number);
                } catch (Throwable $e) {
                    $writer->complete($operation, CarrierOperationResult::unknown('Carrier cancellation was not confirmed.'));

                    Log::warning('Carrier cancellation outcome is unknown', [
                        'shipment_id' => $shipment->id,
                        'exception_type' => $e::class,
                    ]);

                    throw $e;
                }

                $writer->complete($operation, $result);

                if (! $result->success && ! $result->alreadyApplied) {
                    throw new RuntimeException($result->error ?? 'The carrier rejected shipment cancellation.');
                }
            }

            return DB::transaction(function () use ($shipment, $reason) {
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());

                if (! $shipment->isCancellable()) {
                    throw new ShipmentNotCancellableException($shipment->reference, $shipment->status->getValue());
                }

                $oldStatus = $shipment->status;
                $shipment = $oldStatus->transitionTo(Cancelled::class);

                if (! $shipment instanceof Shipment) {
                    throw new RuntimeException('Failed to transition shipment to cancelled state.');
                }

                $shipment->update(['cancelled_at' => CarbonImmutable::now()]);

                $shipment->events()->create([
                    'carrier_event_code' => 'cancelled',
                    'normalized_status' => $shipment->status->toTrackingStatus(),
                    'description' => $reason ?? 'Shipment cancelled',
                    'occurred_at' => CarbonImmutable::now(),
                ]);

                event(new ShipmentCancelled($shipment, $reason));
                event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));

                return $shipment->refresh();
            });
        });
    }
}
