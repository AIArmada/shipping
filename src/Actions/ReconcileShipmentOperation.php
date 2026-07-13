<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Contracts\ShippingDriverInterface;
use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentOperation;
use AIArmada\Shipping\ShippingManager;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReconcileShipmentOperation
{
    use AsAction;

    public function handle(ShipmentOperation $operation): CarrierOperationResult
    {
        if (! $operation->status()->canRetry()) {
            return CarrierOperationResult::fromArray($operation->carrier_result ?? []);
        }

        $shipment = $operation->shipment;

        if ($shipment === null) {
            return CarrierOperationResult::failed('Shipment not found', retryable: false);
        }

        $driverData = $operation->getAttribute('driver_data') ?? $shipment->getAttribute('carrier_code');
        $trackingNumber = $operation->getAttribute('tracking_number') ?? $shipment->getAttribute('tracking_number');

        if ($trackingNumber === null) {
            return CarrierOperationResult::failed('No tracking number to reconcile', retryable: false);
        }

        try {
            $manager = app(\AIArmada\Shipping\ShippingManager::class);
            $driver = $manager->driver((string) $driverData);

            $tracking = $driver->track($trackingNumber);

            if ($tracking->isDelivered()) {
                $result = CarrierOperationResult::succeeded($trackingNumber);
                $operation->complete($result);

                return $result;
            }

            return CarrierOperationResult::unknown('Tracking status: ' . $tracking->status->value);
        } catch (\Throwable $e) {
            return CarrierOperationResult::unknown($e->getMessage());
        }
    }
}
