<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Actions;

use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Returns\Api\RejectReturn;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use AIArmada\Shipping\Features\Returns\States\RmaRejected;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reject a pending return authorization.
 */
final class RejectReturnAuthorization implements RejectReturn
{
    public function execute(string $authorizationId, string $reason, ?string $actorId = null): string
    {
        return DB::transaction(function () use ($authorizationId, $reason, $actorId): string {
            if ((bool) config('shipping.features.owner.enabled', false)) {
                OwnerWriteGuard::findOrFailForOwner(ReturnAuthorization::class, $authorizationId);
            }

            $rma = ReturnAuthorization::query()->lockForUpdate()->findOrFail($authorizationId);
            if (! $rma->isPending()) {
                throw new RuntimeException("Return authorization {$rma->rma_number} is not pending.");
            }

            $resolvedActorId = $actorId ?? (auth()->id() !== null ? (string) auth()->id() : null);

            $transitioned = $rma->status->transitionTo(RmaRejected::class);

            if (! $transitioned instanceof ReturnAuthorization) {
                throw new RuntimeException("Return authorization {$rma->rma_number} could not transition to rejected.");
            }

            $transitioned->update([
                'rejected_at' => CarbonImmutable::now(),
                'rejected_by' => $resolvedActorId,
                'metadata' => array_merge($transitioned->metadata ?? [], [
                    'rejection_reason' => $reason,
                ]),
            ]);

            return (string) $transitioned->getKey();
        });
    }
}
