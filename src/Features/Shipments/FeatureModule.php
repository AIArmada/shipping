<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments;

use AIArmada\Shipping\Features\Shipments\Actions\BuildOrderShipmentData;
use AIArmada\Shipping\Features\Shipments\Actions\IssuePrintableLabel;
use AIArmada\Shipping\Features\Shipments\Actions\MarkStoredShipmentPickedUp;
use AIArmada\Shipping\Features\Shipments\Actions\OperateShipmentBatch;
use AIArmada\Shipping\Features\Shipments\Actions\OperateStoredShipment;
use AIArmada\Shipping\Features\Shipments\Actions\ValidateDestinationAddress;
use AIArmada\Shipping\Features\Shipments\Actions\WriteManualShipment;
use AIArmada\Shipping\Features\Shipments\Api\BuildShipmentFromOrder;
use AIArmada\Shipping\Features\Shipments\Api\BulkShipmentOperations;
use AIArmada\Shipping\Features\Shipments\Api\ManageManualShipment;
use AIArmada\Shipping\Features\Shipments\Api\MarkShipmentPickedUp;
use AIArmada\Shipping\Features\Shipments\Api\OrderShipmentFulfillment;
use AIArmada\Shipping\Features\Shipments\Api\PrintableLabel;
use AIArmada\Shipping\Features\Shipments\Api\ReadReturnShipment;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentOperations;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentStatistics;
use AIArmada\Shipping\Features\Shipments\Api\ValidateDestination;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\Policies\ShipmentPolicy;
use AIArmada\Shipping\Features\Shipments\Queries\FindReturnShipment;
use AIArmada\Shipping\Features\Shipments\Queries\ReadOrderShipmentFulfillment;
use AIArmada\Shipping\Features\Shipments\Queries\ReadShipmentStatistics;
use AIArmada\Shipping\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(BuildShipmentFromOrder::class, BuildOrderShipmentData::class);
        $app->bind(ValidateDestination::class, ValidateDestinationAddress::class);
        $app->bind(ReadReturnShipment::class, FindReturnShipment::class);
        $app->bind(OrderShipmentFulfillment::class, ReadOrderShipmentFulfillment::class);
        $app->bind(ShipmentStatistics::class, ReadShipmentStatistics::class);
        $app->bind(ShipmentOperations::class, OperateStoredShipment::class);
        $app->bind(BulkShipmentOperations::class, OperateShipmentBatch::class);
        $app->bind(ManageManualShipment::class, WriteManualShipment::class);
        $app->bind(MarkShipmentPickedUp::class, MarkStoredShipmentPickedUp::class);
        $app->bind(PrintableLabel::class, IssuePrintableLabel::class);
    }

    public function boot(Application $app): void
    {
        Gate::policy(Shipment::class, ShipmentPolicy::class);
    }
}
