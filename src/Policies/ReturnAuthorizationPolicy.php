<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Policies;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Shipping\Models\ReturnAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

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
     */
    public function view(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        return $this->hasPermission($user, 'shipping.returns.view')
            || $this->isOwner($user, $rma);
    }

    /**
     * Determine whether the user can create return authorizations.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'shipping.returns.create');
    }

    /**
     * Determine whether the user can update the return authorization.
     */
    public function update(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if ($rma->isCompleted() || $rma->isCancelled()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.update');
    }

    /**
     * Determine whether the user can delete the return authorization.
     */
    public function delete(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.delete');
    }

    /**
     * Determine whether the user can approve the return authorization.
     */
    public function approve(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.approve');
    }

    /**
     * Determine whether the user can reject the return authorization.
     */
    public function reject(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isPending()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.reject');
    }

    /**
     * Determine whether the user can receive items for the return.
     */
    public function receive(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isApproved()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.receive');
    }

    /**
     * Determine whether the user can complete the return process.
     */
    public function complete(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! $rma->isReceived()) {
            return false;
        }

        return $this->hasPermission($user, 'shipping.returns.complete');
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

    /**
     * Check if the user owns the return authorization.
     */
    protected function isOwner(Authenticatable $user, ReturnAuthorization $rma): bool
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return false;
        }

        /** @var Model|null $owner */
        $owner = OwnerContext::resolve();

        if ($owner === null) {
            return false;
        }

        if ((bool) config('shipping.features.owner.include_global', false) && $rma->isGlobal()) {
            return true;
        }

        return $rma->belongsToOwner($owner);
    }
}
