<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Returns\Api\ApproveReturn;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use AIArmada\Shipping\Features\Returns\States\RmaApproved;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approve a pending return authorization.
 */
final class ApproveReturnAuthorization implements ApproveReturn
{
    public function execute(string $authorizationId, ?string $notes = null, ?string $actorId = null): string
    {
        return DB::transaction(function () use ($authorizationId, $notes, $actorId): string {
            if ((bool) config('shipping.features.owner.enabled', false)) {
                OwnerWriteGuard::findOrFailForOwner(ReturnAuthorization::class, $authorizationId);
            }

            $rma = ReturnAuthorization::query()->lockForUpdate()->findOrFail($authorizationId);
            if (! $rma->isPending()) {
                throw new RuntimeException("Return authorization {$rma->rma_number} is not pending.");
            }

            $resolvedActorId = $actorId ?? (auth()->id() !== null ? (string) auth()->id() : null);

            if ($rma->isPackageReturn()) {
                app(PackageReturns::class)->approveItems($rma);
            }

            $transitioned = $rma->status->transitionTo(RmaApproved::class);

            if (! $transitioned instanceof ReturnAuthorization) {
                throw new RuntimeException("Return authorization {$rma->rma_number} could not transition to approved.");
            }

            $transitioned->update([
                'approved_at' => CarbonImmutable::now(),
                'approved_by' => $resolvedActorId,
                'metadata' => array_merge($transitioned->metadata ?? [], [
                    'approval_notes' => $notes,
                ]),
            ]);

            return (string) $transitioned->getKey();
        });
    }
}
