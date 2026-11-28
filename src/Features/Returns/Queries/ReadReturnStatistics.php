<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerScopeOverride;
use AIArmada\Shipping\Features\Returns\Api\ReturnStatistics;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReadReturnStatistics implements ReturnStatistics
{
    public function pendingActions(): array
    {
        $counts = $this->returnQuery()->whereIn('status', ['pending', 'approved'])
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->toBase()->pluck('aggregate', 'status');

        return ['pendingReturns' => (int) $counts->get('pending', 0), 'approvedReturns' => (int) $counts->get('approved', 0)];
    }

    public function pendingCount(): int
    {
        return $this->returnQuery()->where('status', 'pending')->count();
    }

    /** @return Builder<ReturnAuthorization> */
    private function returnQuery(): Builder
    {
        $query = ReturnAuthorization::query()->withoutGlobalScope(OwnerScope::class);

        if ((bool) config('shipping.features.owner.enabled', false)) {
            $owner = OwnerContext::resolve();

            if ($owner === null && ! OwnerContext::isExplicitGlobal()) {
                return $query->whereRaw('0 = 1');
            }

            $query->forOwner($owner, includeGlobal: ! OwnerScopeOverride::suppressIncludeGlobal() && (bool) config('shipping.features.owner.include_global', false));
        }

        return $query;
    }
}
