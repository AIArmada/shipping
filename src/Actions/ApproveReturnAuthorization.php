<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaApproved;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Approve a pending return authorization.
 */
final class ApproveReturnAuthorization
{
    use AsAction;

    public function handle(ReturnAuthorization $rma, ?string $notes = null, ?string $actorId = null): ReturnAuthorization
    {
        if (! $rma->isPending()) {
            throw new RuntimeException("Return authorization {$rma->rma_number} is not pending.");
        }

        $resolvedActorId = $actorId ?? (auth()->id() !== null ? (string) auth()->id() : null);

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

        return $transitioned->refresh();
    }
}
