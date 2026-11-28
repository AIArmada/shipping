<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Policies;

use AIArmada\CommerceSupport\Support\Filament\OwnerUiScope;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

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
     *
     * Allows either a permissioned admin (with owner boundary when enabled) or
     * the tenant owner of the shipment (customer self-service without admin permission).
     */
    public function view(Authenticatable $user, Shipment $shipment): bool
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return $this->hasPermission($user, 'shipping.shipments.view');
        }

        return OwnerUiScope::canAccessRecord($shipment);
    }

    /**
     * Determine whether the user can create shipments.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.shipments.create')
            && (! (bool) config('shipping.features.owner.enabled', false) || OwnerUiScope::canCreate(Shipment::class));
    }

    /**
     * Determine whether the user can update the shipment.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function update(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->isTerminal()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.update')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
    }

    /**
     * Determine whether the user can delete the shipment.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function delete(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isCancellable()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.delete')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
    }

    /**
     * Determine whether the user can ship the shipment.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function ship(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isPending()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.ship')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
    }

    /**
     * Determine whether the user can cancel the shipment.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function cancel(Authenticatable $user, Shipment $shipment): bool
    {
        if (! $shipment->isCancellable()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.cancel')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
    }

    /**
     * Determine whether the user can print labels.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function printLabel(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->tracking_number === null) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.print-label')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
    }

    /**
     * Determine whether the user can sync tracking.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function syncTracking(Authenticatable $user, Shipment $shipment): bool
    {
        if ($shipment->tracking_number === null) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.shipments.sync-tracking')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($shipment);
        }

        return true;
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
        if ($user instanceof Authorizable) {
            return $user->can($permission);
        }

        return false;
    }
}
