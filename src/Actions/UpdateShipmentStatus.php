<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Enums\ShipmentStatus as ShipmentStatusEnum;
use AIArmada\Shipping\Exceptions\InvalidStatusTransitionException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\Delivered;
use AIArmada\Shipping\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\States\Shipped;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Update the status of a shipment.
 */
final class UpdateShipmentStatus
{
    use AsAction;

    /**
     * Update the status of a shipment.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Shipment $shipment,
        ShipmentStatusState | ShipmentStatusEnum | string $status,
        ?string $description = null,
        ?string $location = null,
        array $metadata = []
    ): Shipment {
        return DB::transaction(function () use ($shipment, $status, $description, $location, $metadata): Shipment {
            $previousStatus = $shipment->status;

            $nextStatusClass = ShipmentStatusState::resolveStateClassFor($status, $shipment);
            $nextStatus = new $nextStatusClass($shipment);

            if (! $previousStatus->canTransitionTo($nextStatusClass)) {
                throw new InvalidStatusTransitionException($previousStatus, $nextStatus);
            }

            $shipment->forceFill([
                'status' => $nextStatus,
            ]);

            // Update timestamp fields based on status
            if ($shipment->status instanceof Shipped && $shipment->shipped_at === null) {
                $shipment->shipped_at = now();
            }

            if ($shipment->status instanceof Delivered && $shipment->delivered_at === null) {
                $shipment->delivered_at = now();
            }

            $shipment->save();

            // Create event record
            $shipment->events()->create([
                'normalized_status' => $nextStatus->toTrackingStatus(),
                'description' => $description,
                'location' => $location,
                'raw_data' => $metadata ?: null,
                'occurred_at' => now(),
            ]);

            return $shipment->refresh();
        });
    }
}
