<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface ReadReturnShipment
{
    public function find(string $shipmentId): ReturnShipment;

    public function linkedShipmentId(string $type, string $id): ?string;

    public function isPackageComponent(?string $type, ?string $id): bool;

    /** @return list<ReturnShipmentLine> */
    public function packageLines(string $shipmentId): array;

    /** @return array<int, ReturnShipmentLine> */
    public function unitLines(string $shipmentId, string $orderItemId, int $ordinal): array;
}
