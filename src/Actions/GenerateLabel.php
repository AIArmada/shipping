<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Models\ShipmentLabel;
use AIArmada\Shipping\ShippingManager;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class GenerateLabel
{
    use AsAction;

    public function __construct(
        protected readonly ShippingManager $shippingManager,
    ) {}

    public function handle(Shipment $shipment, array $options = []): ShipmentLabel
    {
        if ($shipment->tracking_number === null) {
            throw new RuntimeException('Cannot generate label for shipment without tracking number');
        }

        $driver = $this->shippingManager->driver($shipment->carrier_code);

        $labelData = $driver->generateLabel($shipment->tracking_number, $options);

        $label = $shipment->labels()->create([
            'format' => $labelData->format,
            'size' => $labelData->size,
            'url' => $labelData->url,
            'content' => $labelData->content,
            'generated_at' => CarbonImmutable::now(),
        ]);

        if ($labelData->url !== null) {
            $shipment->update([
                'label_url' => $labelData->url,
                'label_format' => $labelData->format,
            ]);
        }

        return $label;
    }
}
