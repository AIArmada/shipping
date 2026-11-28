<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns;

use AIArmada\Shipping\Features\Returns\Actions\ApproveReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Actions\ManagePackageReturn;
use AIArmada\Shipping\Features\Returns\Actions\RejectReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Actions\WriteReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Api\ApproveReturn;
use AIArmada\Shipping\Features\Returns\Api\ManageReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Api\PackageReturnWorkflow;
use AIArmada\Shipping\Features\Returns\Api\RejectReturn;
use AIArmada\Shipping\Features\Returns\Api\ReturnStatistics;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Policies\ReturnAuthorizationPolicy;
use AIArmada\Shipping\Features\Returns\Queries\ReadReturnStatistics;
use AIArmada\Shipping\Platform\FeatureModule as FeatureModuleContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;

final class FeatureModule implements FeatureModuleContract
{
    public function register(Application $app): void
    {
        $app->bind(ReturnStatistics::class, ReadReturnStatistics::class);
        $app->bind(PackageReturnWorkflow::class, ManagePackageReturn::class);
        $app->bind(ApproveReturn::class, ApproveReturnAuthorization::class);
        $app->bind(ManageReturnAuthorization::class, WriteReturnAuthorization::class);
        $app->bind(RejectReturn::class, RejectReturnAuthorization::class);
    }

    public function boot(Application $app): void
    {
        Gate::policy(ReturnAuthorization::class, ReturnAuthorizationPolicy::class);
    }
}
