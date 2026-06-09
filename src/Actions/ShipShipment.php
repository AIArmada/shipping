<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Enums\DriverCapability;
use AIArmada\Shipping\Events\ShipmentShipped;
use AIArmada\Shipping\Exceptions\ShipmentAlreadyShippedException;
use AIArmada\Shipping\Exceptions\ShipmentCreationFailedException;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Services\RetryService;
use AIArmada\Shipping\ShippingManager;
use AIArmada\Shipping\States\Shipped;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

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
        if (! $shipment->isPending()) {
            throw new ShipmentAlreadyShippedException($shipment);
        }

        $driver = $this->shippingManager->driver($shipment->carrier_code);

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

        if (! $result->isSuccessful()) {
            throw new ShipmentCreationFailedException($result->error ?? 'Unknown error');
        }

        return DB::transaction(function () use ($shipment, $result, $driver) {
            $shipment = $shipment->status->transitionTo(Shipped::class);
            if (! $shipment instanceof Shipment) {
                throw new RuntimeException('Failed to update shipment status.');
            }

            $shipment->update([
                'tracking_number' => $result->trackingNumber,
                'carrier_reference' => $result->carrierReference,
                'shipped_at' => CarbonImmutable::now(),
            ]);

            if ($result->labelUrl !== null) {
                $shipment->update(['label_url' => $result->labelUrl]);
            }

            if ($result->labelUrl === null && $driver->supports(DriverCapability::LabelGeneration)) {
                try {
                    $this->labelGenerator()->handle($shipment);
                } catch (Throwable $e) {
                    Log::warning('Label generation failed for shipment', [
                        'shipment_id' => $shipment->id,
                        'tracking_number' => $shipment->tracking_number,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $shipment->events()->create([
                'carrier_event_code' => 'shipped',
                'normalized_status' => $shipment->status->toTrackingStatus(),
                'description' => 'Shipment created with carrier',
                'occurred_at' => CarbonImmutable::now(),
            ]);

            event(new ShipmentShipped($shipment));

            return $shipment->refresh();
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
