<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Policies;

use AIArmada\Shipping\Models\ShippingZone;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;

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
     */
    public function view(Authenticatable $user, ShippingZone $zone): bool
    {
        return $this->hasPermission($user, 'shipping.zones.view');
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
     */
    public function update(Authenticatable $user, ShippingZone $zone): bool
    {
        return $this->hasPermission($user, 'shipping.zones.update');
    }

    /**
     * Determine whether the user can delete the shipping zone.
     */
    public function delete(Authenticatable $user, ShippingZone $zone): bool
    {
        // Prevent deletion of zones with active rates
        if ($zone->rates()->exists()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.zones.delete');
    }

    /**
     * Determine whether the user can manage rates within the zone.
     */
    public function manageRates(Authenticatable $user, ShippingZone $zone): bool
    {
        return $this->hasPermission($user, 'shipping.zones.manage-rates');
    }

    /**
     * Check if user has a specific permission.
     */
    protected function hasPermission(Authenticatable $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo($permission);
        }

        if (method_exists($user, 'can')) {
            return $user->can($permission);
        }

        return false;
    }
}
