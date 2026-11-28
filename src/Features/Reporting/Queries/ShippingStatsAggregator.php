<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Reporting\Queries;

use AIArmada\Shipping\Features\Reporting\Api\DashboardStats;
use AIArmada\Shipping\Features\Returns\Api\ReturnStatistics;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentStatistics;

final readonly class ShippingStatsAggregator implements DashboardStats
{
    public function __construct(private ShipmentStatistics $shipments, private ReturnStatistics $returns) {}

    public function getAllStats(): array
    {
        return [...$this->shipments->getAllStats(), 'pendingReturns' => $this->returns->pendingCount()];
    }

    public function pendingActions(): array
    {
        return [...$this->shipments->pendingActions(), ...$this->returns->pendingActions()];
    }

    public function carrierPerformance(): array
    {
        return $this->shipments->carrierPerformance();
    }
}
