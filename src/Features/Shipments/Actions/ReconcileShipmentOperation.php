<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentOperation;
use AIArmada\Shipping\Features\Shipments\Persistence\ShipmentOperationWriter;
use AIArmada\Shipping\ShippingManager;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ReconcileShipmentOperation
{
    use AsAction;

    public function handle(ShipmentOperation $operation): CarrierOperationResult
    {
        if ($operation->status() !== ShipmentOperationStatus::Unknown) {
            return CarrierOperationResult::fromArray($operation->carrier_result ?? []);
        }

        $shipment = $operation->shipment;

        if ($shipment === null) {
            return CarrierOperationResult::failed('Shipment not found', retryable: false);
        }

        // Tracking confirms existence, not cancellation. No normalized tracking
        // status establishes that a carrier accepted a cancellation.
        if ($operation->getAttribute('operation_type') !== 'create') {
            return CarrierOperationResult::unknown('Carrier confirmation is required to reconcile this operation.');
        }

        $driverData = $shipment->carrier_code;
        $trackingNumber = $operation->getAttribute('tracking_number') ?? $shipment->getAttribute('tracking_number');

        if ($trackingNumber === null) {
            return CarrierOperationResult::unknown('No tracking number to reconcile');
        }

        try {
            $manager = app(ShippingManager::class);
            $driver = $manager->driver((string) $driverData);

            $tracking = $driver->track($trackingNumber);
            $provesCreation = in_array($tracking->status->getCategory(), [
                'pre_shipment',
                'in_transit',
                'out_for_delivery',
                'delivered',
            ], true);

            if ($tracking->trackingNumber === $trackingNumber && $provesCreation) {
                $result = CarrierOperationResult::succeeded($trackingNumber);
                app(ShipmentOperationWriter::class)->complete($operation, $result);

                return $result;
            }

            return CarrierOperationResult::unknown('Tracking status: ' . $tracking->status->value);
        } catch (Throwable $e) {
            return CarrierOperationResult::unknown($e->getMessage());
        }
    }
}
