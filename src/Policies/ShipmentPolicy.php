<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Shipping\Models\Shipment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for Shipment model authorization.
 *
 * Provides granular access control for shipment operations.
 */
class ShipmentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any shipments.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.shipments.view');
    }

    /**
     * Determine whether the user can view the shipment.
     */
    public function view(Authenticatable $user, Shipment $shipment): bool
    {
        return $this->hasPermission($user, 'shipping.shipments.view')
            || $this->isOwner($user, $shipment);
    }

    /**
     * Determine whether the user can create shipments.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.shipments.create');
    }

    /**
     * Determine whether the user can update the shipment.
     */
    public function update(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->isTerminal()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.update')
            || $this->isOwner($user, $shipment);
    }

    /**
     * Determine whether the user can delete the shipment.
     */
    public function delete(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isCancellable()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.delete');
    }

    /**
     * Determine whether the user can ship the shipment.
     */
    public function ship(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isPending()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.ship');
    }

    /**
     * Determine whether the user can cancel the shipment.
     */
    public function cancel(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isCancellable()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.cancel');
    }

    /**
     * Determine whether the user can print labels.
     */
    public function printLabel(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->tracking_number === null) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.print-label')
            || $this->isOwner($user, $shipment);
    }

    /**
     * Determine whether the user can sync tracking.
     */
    public function syncTracking(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->tracking_number === null) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.shipments.sync-tracking');
    }

    /**
     * Check if user has a specific permission.
     */
    protected function hasPermission(Authenticatable $user, string $permission): bool
    {
        // Check for Spatie permission
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo($permission);
        }

        // Check for can method
        if (method_exists($user, 'can')) {
            return $user->can($permission);
        }

        return false;
    }

    /**
     * Check if the user owns the shipment.
     */
    protected function isOwner(Authenticatable $user, Shipment $shipment): bool
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return false;
        }

        /** @var Model|null $owner */
        $owner = OwnerContext::resolve();

        if ($owner === null) {
            return false;
        }

        if ((bool) config('shipping.features.owner.include_global', false) && $shipment->isGlobal()) {
            return true;
        }

        return $shipment->belongsToOwner($owner);
    }
}
