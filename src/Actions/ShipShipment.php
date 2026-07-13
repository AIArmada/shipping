<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Events\ShipmentShipped;
use AIArmada\Shipping\Exceptions\ShipmentAlreadyShippedException;
use AIArmada\Shipping\Exceptions\ShipmentCreationFailedException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentOperation;
use AIArmada\Shipping\Services\RetryService;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Shipped;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ShipShipment
{
    use AsAction;

    public function __construct(
        protected readonly ShippingManager $shippingManager,
        protected readonly ?GenerateLabel $generateLabel = null,
        protected readonly ?RetryService $retryService = null,
    ) {}

    public function handle(Shipment $shipment): Shipment
    {
        $lockKey = "shipment:{$shipment->getKey()}:ship";

        return Cache::lock($lockKey, 30)->block(30, function () use ($shipment): Shipment {
            if (! $shipment->isPending()) {
                throw new ShipmentAlreadyShippedException($shipment);
            }

            $driver = $this->shippingManager->driver($shipment->carrier_code);

            $operation = ShipmentOperation::recordStart(
                (string) $shipment->getKey(),
                'create',
                $shipment->reference,
            );

            if ($operation->status() !== ShipmentOperationStatus::Pending) {
                throw new ShipmentCreationFailedException('Another shipment operation is already in progress or completed.');
            }

            $result = $this->retry()
                ->attempts(3)
                ->delay(200)
                ->backoff(2.0)
                ->execute(
                    fn () => $driver->createShipment(
                        ShipmentData::from([
                            'reference' => $shipment->reference,
                            'carrierCode' => $shipment->carrier_code,
                            'serviceCode' => $shipment->service_code ?? 'standard',
                            'origin' => $shipment->origin_address,
                            'destination' => $shipment->destination_address,
                            'items' => $shipment->items->map(fn ($item) => [
                                'name' => $item->name,
                                'quantity' => $item->quantity,
                                'sku' => $item->sku,
                                'weight' => $item->weight,
                                'declaredValue' => $item->declared_value,
                            ])->toArray(),
                            'declaredValue' => $shipment->declared_value,
                            'currency' => $shipment->currency,
                            'codAmount' => $shipment->cod_amount,
                        ])
                    ),
                    context: "ship:{$shipment->id}"
                );

            $operation->complete($result);

            if (! $result->success && ! $result->alreadyApplied) {
                throw new ShipmentCreationFailedException($result->error ?? 'Unknown error during carrier shipment creation');
            }

            return DB::transaction(function () use ($shipment, $result) {
                $shipment = $shipment->status->transitionTo(Shipped::class);
                if (! $shipment instanceof Shipment) {
                    throw new RuntimeException('Failed to update shipment status.');
                }

                $shipment->update([
                    'tracking_number' => $result->trackingNumber ?? $shipment->tracking_number,
                    'carrier_reference' => $result->carrierReference ?? $shipment->carrier_reference,
                    'shipped_at' => CarbonImmutable::now(),
                ]);

                $shipment->events()->create([
                    'carrier_event_code' => 'shipped',
                    'normalized_status' => $shipment->status->toTrackingStatus(),
                    'description' => 'Shipment created with carrier',
                    'occurred_at' => CarbonImmutable::now(),
                ]);

                event(new ShipmentShipped($shipment));

                return $shipment->refresh();
            });
        });
    }

    protected function retry(): RetryService
    {
        return $this->retryService ?? RetryService::make();
    }

    protected function labelGenerator(): GenerateLabel
    {
        return $this->generateLabel ?? new GenerateLabel($this->shippingManager);
    }
}
