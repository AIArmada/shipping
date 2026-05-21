<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\ReturnAuthorization;
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

        $rma->update([
            'status' => 'approved',
            'approved_at' => CarbonImmutable::now(),
            'approved_by' => $resolvedActorId,
            'metadata' => array_merge($rma->metadata ?? [], [
                'approval_notes' => $notes,
            ]),
        ]);

        return $rma->refresh();
    }
}
