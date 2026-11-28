<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Policies;

use AIArmada\CommerceSupport\Support\Filament\OwnerUiScope;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy for ReturnAuthorization model authorization.
 *
 * Controls access to return merchandise authorization operations.
 */
class ReturnAuthorizationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any return authorizations.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.returns.view');
    }

    /**
     * Determine whether the user can view the return authorization.
     *
     * Allows either a permissioned admin (with owner boundary when enabled)
     * or the tenant owner of the RMA (customer self-service).
     */
    public function view(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return $this->hasPermission($user, 'shipping.returns.view');
        }

        return OwnerUiScope::canAccessRecord($rma);
    }

    /**
     * Determine whether the user can create return authorizations.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.returns.create')
            && (! (bool) config('shipping.features.owner.enabled', false) || OwnerUiScope::canCreate(ReturnAuthorization::class));
    }

    /**
     * Determine whether the user can update the return authorization.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function update(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if ($rma->isCompleted() || $rma->isCancelled()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.update')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
        }

        return true;
    }

    /**
     * Determine whether the user can delete the return authorization.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function delete(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.delete')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
        }

        return true;
    }

    /**
     * Determine whether the user can approve the return authorization.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function approve(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.approve')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
        }

        return true;
    }

    /**
     * Determine whether the user can reject the return authorization.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function reject(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.reject')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
        }

        return true;
    }

    /**
     * Determine whether the user can receive items for the return.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function receive(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isApproved()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.receive')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
        }

        return true;
    }

    /**
     * Determine whether the user can complete the return process.
     *
     * When owner mode is enabled, permission is necessary but not sufficient — the
     * owner boundary must also pass to prevent cross-tenant IDOR.
     */
    public function complete(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isReceived()) {
            return false;
        }

        if (! $this->hasPermission($user, 'shipping.returns.complete')) {
            return false;
        }

        if ((bool) config('shipping.features.owner.enabled', false)) {
            return OwnerUiScope::canMutateRecord($rma);
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
}
