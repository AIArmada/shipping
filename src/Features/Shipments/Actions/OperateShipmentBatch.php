<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Shipments\Api\BulkShipmentOperations;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentBatchResult;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentOperations;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Persistence\BatchRateLimiter;
use Closure;
use Illuminate\Support\Facades\Gate;
use Throwable;

final readonly class OperateShipmentBatch implements BulkShipmentOperations
{
    public function __construct(private ShipmentOperations $operations) {}

    public function ship(array $shipmentIds): ShipmentBatchResult
    {
        return $this->execute($shipmentIds, 'ship', $this->operations->ship(...));
    }

    public function cancel(array $shipmentIds): ShipmentBatchResult
    {
        return $this->execute($shipmentIds, 'cancel', $this->operations->cancel(...));
    }

    public function syncTracking(array $shipmentIds): ShipmentBatchResult
    {
        return $this->execute($shipmentIds, 'syncTracking', $this->operations->syncTracking(...));
    }

    /**
     * @param  list<string>  $shipmentIds
     * @param  Closure(string): void  $operation
     */
    private function execute(array $shipmentIds, string $ability, Closure $operation): ShipmentBatchResult
    {
        $byCarrier = [];
        $succeeded = 0;
        $failed = 0;

        foreach (array_unique($shipmentIds) as $id) {
            try {
                $shipment = (bool) config('shipping.features.owner.enabled', false)
                    ? OwnerWriteGuard::findOrFailForOwner(Shipment::class, $id)
                    : Shipment::query()->findOrFail($id);

                if (! Gate::allows($ability, $shipment)) {
                    $failed++;

                    continue;
                }

                $byCarrier[$shipment->carrier_code][] = $id;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        foreach ($byCarrier as $carrier => $ids) {
            $results = BatchRateLimiter::forCarrier((string) $carrier)->execute(
                $ids,
                static function (string $id) use ($operation): void {
                    try {
                        $operation($id);
                    } catch (Throwable $exception) {
                        report($exception);

                        throw $exception;
                    }
                },
                $ability === 'syncTracking' ? 'sync_tracking' : $ability,
            );

            foreach ($results as $result) {
                if ($result['success']) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
        }

        return new ShipmentBatchResult($succeeded, $failed);
    }
}
