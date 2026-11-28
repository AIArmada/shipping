<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Policies;

use AIArmada\CommerceSupport\Support\Filament\OwnerUiScope;
use AIArmada\Shipping\Features\Rates\Models\ShippingRate;
use AIArmada\Shipping\Features\Rates\Models\ShippingZone;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

final class ShippingRatePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.zones.view');
    }

    public function view(Authenticatable $user, ShippingRate $rate): bool
    {
        $zone = $rate->zone;

        return $zone instanceof ShippingZone
            && $this->hasPermission($user, 'shipping.zones.view')
            && OwnerUiScope::canAccessRecord($zone);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->canManageRates($user);
    }

    public function update(Authenticatable $user, ShippingRate $rate): bool
    {
        $zone = $rate->zone;

        return $zone instanceof ShippingZone
            && $this->canManageRates($user)
            && OwnerUiScope::canMutateRecord($zone);
    }

    public function delete(Authenticatable $user, ShippingRate $rate): bool
    {
        return $this->update($user, $rate);
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->canManageRates($user);
    }

    private function canManageRates(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.zones.manage-rates')
            && (! (bool) config('shipping.features.owner.enabled', false) || OwnerUiScope::canCreate(ShippingZone::class));
    }

    private function hasPermission(Authenticatable $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo($permission);
        }

        if ($user instanceof Authorizable) {
            return $user->can($permission);
        }

        return false;
    }
}
