<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Reporting;

use AIArmada\Shipping\Features\Reporting\Api\DashboardStats;
use AIArmada\Shipping\Features\Reporting\Queries\ShippingStatsAggregator;
use AIArmada\Shipping\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(DashboardStats::class, ShippingStatsAggregator::class);
    }

    public function boot(Application $app): void {}
}
