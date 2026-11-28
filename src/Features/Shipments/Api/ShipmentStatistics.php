<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Api;

interface ShipmentStatistics
{
    /** @return array{total: int, pending: int, inTransit: int, deliveredToday: int, exceptions: int} */
    public function getAllStats(): array;

    /** @return array{pending: int, exceptions: int} */
    public function pendingActions(): array;

    /** @return list<array{carrier: string, delivered: int, inTransit: int, exceptions: int}> */
    public function carrierPerformance(): array;
}
