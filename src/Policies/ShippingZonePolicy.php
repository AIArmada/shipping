<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Shipping\Models\ShippingZone;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for ShippingZone model authorization.
 *
 * Controls access to shipping zone configuration.
 */
class ShippingZonePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any shipping zones.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.zones.view');
    }

    /**
     * Determine whether the user can view the shipping zone.
     *
     * Allows either a permissioned admin (with owner boundary when enabled) or
     * the tenant owner of the zone.
     */
    public function view(Authenticatable $user, ShippingZone $zone): bool
    {
        if ($this->hasPermission($user, 'shipping.zones.view')) {
            if ((bool) config('shipping.features.owner.enabled', false)) {
                return $this->isOwner($user, $zone);
            }

            return true;
        }

        return $this->isOwner($user, $zone);
    }

    /**
     * Determine whether the user can create shipping zones.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.zones.create');
    }

    /**
     * Determine whether the user can update the shipping zone.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function update(Authenticatable $user, ShippingZone $zone): bool
    {
        if (! $this->hasPermission($user, 'shipping.zones.update')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return $this->isOwner($user, $zone);
        }

        return true;
    }

    /**
     * Determine whether the user can delete the shipping zone.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function delete(Authenticatable $user, ShippingZone $zone): bool
    {
        // Prevent deletion of zones with active rates.
        // Use rates_count (from withCount) when available to avoid N+1 in table views.
        $hasRates = isset($zone->rates_count)
            ? $zone->rates_count > 0
            : $zone->rates()->exists();

        if ($hasRates) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.zones.delete')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return $this->isOwner($user, $zone);
        }

        return true;
    }

    /**
     * Determine whether the user can manage rates within the zone.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function manageRates(Authenticatable $user, ShippingZone $zone): bool
    {
        if (! $this->hasPermission($user, 'shipping.zones.manage-rates')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return $this->isOwner($user, $zone);
        }

        return true;
    }

    /**
     * Check if user has a specific permission.
     */
    protected function hasPermission(Authenticatable $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo($permission);
        }

        if ($user instanceof Authorizable) {
            return $user->can($permission);
        }

        return false;
    }

    /**
     * Check if the user owns the shipping zone.
     */
    protected function isOwner(Authenticatable $user, ShippingZone $zone): bool
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return false;
        }

        /** @var Model|null $owner */
        $owner = OwnerContext::resolve();

        if ($owner === null) {
            return false;
        }

        if ((bool) config('shipping.features.owner.include_global', false) && $zone->isGlobal()) {
            return true;
        }

        return $zone->belongsToOwner($owner);
    }
}
