<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Actions;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;
use AIArmada\Shipping\Data\CarrierOperationResult;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentItemData;
use AIArmada\Shipping\Enums\ShipmentOperationStatus;
use AIArmada\Shipping\Exceptions\ShipmentAlreadyShippedException;
use AIArmada\Shipping\Exceptions\ShipmentCreationFailedException;
use AIArmada\Shipping\Features\Shipments\Events\ShipmentShipped;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Models\ShipmentOperation;
use AIArmada\Shipping\Features\Shipments\Persistence\ShipmentOperationWriter;
use AIArmada\Shipping\Features\Shipments\Queries\OrderShipmentUnits;
use AIArmada\Shipping\Features\Shipments\States\Shipped;
use AIArmada\Shipping\ShippingManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class ShipShipment
{
    use AsAction;

    public function __construct(
        protected readonly ShippingManager $shippingManager,
        protected readonly ?GenerateLabel $generateLabel = null,
    ) {}

    public function handle(Shipment $shipment): Shipment
    {
        $lockKey = "shipment:{$shipment->getKey()}:ship";

        return Cache::lock($lockKey, 30)->block(30, function () use ($shipment): Shipment {
            $shipment->refresh();

            $hasPackageUnits = $shipment->items()->whereNotNull('package_unit_ordinal')->exists();
            if ($shipment->shipped_at !== null && $hasPackageUnits) {
                return $shipment;
            }

            if (! $shipment->isPending()) {
                throw new ShipmentAlreadyShippedException($shipment->reference);
            }

            $order = $shipment->shippable;
            if ($order instanceof Order && $order->items()->get()->contains(fn ($item): bool => PackageUnit::hasShippableComponents($item))) {
                if ($order->isCanceled() || $order->isFinal()) {
                    throw new ShipmentCreationFailedException('This order can no longer ship package units.');
                }

                OrderShipmentUnits::assertComplete($shipment, $order);
            }

            if ($shipment->operations()->where('operation_type', 'cancel')
                ->whereIn('status', [ShipmentOperationStatus::Pending->value, ShipmentOperationStatus::Unknown->value, ShipmentOperationStatus::Succeeded->value])->exists()) {
                throw new ShipmentCreationFailedException('Reconcile the cancellation before shipping this shipment.');
            }

            $priorOperation = ShipmentOperation::query()
                ->where('shipment_id', $shipment->id)
                ->where('operation_type', 'create')
                ->orderByDesc('operation_started_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $priorStatus = $priorOperation?->status();
            if ($priorStatus === ShipmentOperationStatus::Pending || $priorStatus === ShipmentOperationStatus::Unknown) {
                throw new ShipmentCreationFailedException('Reconcile the existing carrier operation before retrying this shipment.');
            }

            if ($priorStatus === ShipmentOperationStatus::Succeeded) {
                $result = CarrierOperationResult::fromArray($priorOperation->carrier_result ?? []);
            } else {
                $driver = $this->shippingManager->driver($shipment->carrier_code);

                $operation = app(ShipmentOperationWriter::class)->start(
                    $shipment,
                    'create',
                    $shipment->reference,
                );

                if (! $operation->wasRecentlyCreated || $operation->status() !== ShipmentOperationStatus::Pending) {
                    throw new ShipmentCreationFailedException('Another shipment operation is already in progress or completed.');
                }

                try {
                    $result = $driver->createShipment(
                        ShipmentData::from([
                            'reference' => $shipment->reference,
                            'carrierCode' => $shipment->carrier_code,
                            'serviceCode' => $shipment->service_code ?? 'standard',
                            'origin' => $shipment->origin_address,
                            'destination' => $shipment->destination_address,
                            'items' => $shipment->items()->get()->map(fn ($item): ShipmentItemData => ShipmentItemData::from([
                                'name' => $item->name,
                                'quantity' => $item->quantity,
                                'sku' => $item->sku,
                                'weight' => $item->weight,
                                'declaredValue' => $item->declared_value,
                            ]))->all(),
                            'packages' => array_map(
                                fn (array $parcel): PackageData => PackageData::from($parcel),
                                $shipment->metadata['packages'] ?? [],
                            ),
                            'declaredValue' => $shipment->declared_value,
                            'currency' => $shipment->currency,
                            'codAmount' => $shipment->cod_amount,
                        ])
                    );
                } catch (Throwable $e) {
                    app(ShipmentOperationWriter::class)->complete($operation, CarrierOperationResult::unknown($e->getMessage()));

                    throw $e;
                }

                app(ShipmentOperationWriter::class)->complete($operation, $result);
            }

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

    protected function labelGenerator(): GenerateLabel
    {
        return $this->generateLabel ?? new GenerateLabel($this->shippingManager);
    }
}
