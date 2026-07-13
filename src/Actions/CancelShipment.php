<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Events\ShipmentCancelled;
use AIArmada\Shipping\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Exceptions\ShipmentNotCancellableException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentOperation;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Cancelled;
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
        $lockKey = "shipment:{$shipment->getKey()}:cancel";

        return Cache::lock($lockKey, 30)->block(30, function () use ($shipment, $reason): Shipment {
            if (! $shipment->isCancellable()) {
                throw new ShipmentNotCancellableException($shipment);
            }

            $driver = $this->shippingManager->driver($shipment->carrier_code);

            $operation = ShipmentOperation::recordStart(
                (string) $shipment->getKey(),
                'cancel',
                $shipment->carrier_reference,
            );

            if ($shipment->tracking_number !== null) {
                try {
                    $result = $driver->cancelShipment($shipment->tracking_number);
                    $operation->complete($result);

                    if (! $result->success && ! $result->alreadyApplied) {
                        Log::warning('Carrier cancellation returned failure after operation recorded', [
                            'shipment_id' => $shipment->id,
                            'tracking_number' => $shipment->tracking_number,
                            'error' => $result->error,
                        ]);

                        return $shipment;
                    }
                } catch (Throwable $e) {
                    $operation->complete(
                        CarrierOperationResult::unknown($e->getMessage()),
                    );
                    Log::warning('Carrier cancellation threw after operation recorded', [
                        'shipment_id' => $shipment->id,
                        'tracking_number' => $shipment->tracking_number,
                        'error' => $e->getMessage(),
                    ]);

                    return $shipment;
                }
            }

            return DB::transaction(function () use ($shipment, $reason) {
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
