<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface BulkShipmentOperations
{
    /** @param list<string> $shipmentIds */
    public function ship(array $shipmentIds): ShipmentBatchResult;

    /** @param list<string> $shipmentIds */
    public function cancel(array $shipmentIds): ShipmentBatchResult;

    /** @param list<string> $shipmentIds */
    public function syncTracking(array $shipmentIds): ShipmentBatchResult;
}
