<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaRejected;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Reject a pending return authorization.
 */
final class RejectReturnAuthorization
{
    use AsAction;

    public function handle(ReturnAuthorization $rma, string $reason, ?string $actorId = null): ReturnAuthorization
    {
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

        return $transitioned->refresh();
    }
}
