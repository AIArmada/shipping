<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Integrations\PackageReturns;
use AIArmada\Shipping\Models\ReturnAuthorization;
use AIArmada\Shipping\States\ReturnAuthorizationState\RmaApproved;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($rma, $notes, $actorId): ReturnAuthorization {
            $rma = ReturnAuthorization::query()->lockForUpdate()->findOrFail($rma->getKey());
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

            return $transitioned->refresh();
        });
    }
}
