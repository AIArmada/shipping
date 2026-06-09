<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Events\ShipmentCancelled;
use AIArmada\Shipping\Events\ShipmentStatusChanged;
use AIArmada\Shipping\Exceptions\ShipmentNotCancellableException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Cancelled;
use Carbon\CarbonImmutable;
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
        if (! $shipment->isCancellable()) {
            throw new ShipmentNotCancellableException($shipment);
        }

        return DB::transaction(function () use ($shipment, $reason) {
            $oldStatus = $shipment->status;
            $shipment = $oldStatus->transitionTo(Cancelled::class);
            if (! $shipment instanceof Shipment) {
                throw new RuntimeException('Failed to update shipment status.');
            }

            $shipment->events()->create([
                'carrier_event_code' => 'cancelled',
                'normalized_status' => $shipment->status->toTrackingStatus(),
                'description' => $reason,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            event(new ShipmentCancelled($shipment, $reason));
            event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));

            if ($shipment->tracking_number !== null) {
                try {
                    $driver = $this->shippingManager->driver($shipment->carrier_code);
                    $driver->cancelShipment($shipment->tracking_number);
                } catch (Throwable $e) {
                    Log::warning('Carrier cancel failed after DB cancel — manual follow-up may be required', [
                        'shipment_id' => $shipment->id,
                        'tracking_number' => $shipment->tracking_number,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $shipment->refresh();
        });
    }
}
