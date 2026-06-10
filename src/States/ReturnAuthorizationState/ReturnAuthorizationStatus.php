<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States\ReturnAuthorizationState;

use AIArmada\Shipping\Models\ReturnAuthorization;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @method ReturnAuthorization getModel()
 */
abstract class ReturnAuthorizationStatus extends State
{
    abstract public function label(): string;

    abstract public function color(): string;

    abstract public function icon(): string;

    public function isPending(): bool
    {
        return false;
    }

    public function isApproved(): bool
    {
        return false;
    }

    public function isRejected(): bool
    {
        return false;
    }

    public function isTerminal(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function options(?Model $model = null): array
    {
        $model ??= new ReturnAuthorization;

        $options = [];

        /** @var class-string<ReturnAuthorizationStatus> $stateClass */
        foreach (self::all()->all() as $stateClass) {
            $state = new $stateClass($model);
            $options[$state->getValue()] = $state->label();
        }

        return $options;
    }

    final public static function config(): StateConfig
    {
        return parent::config()
            ->default(RmaDraft::class)
            ->allowTransition(RmaDraft::class, RmaPending::class)
            ->allowTransition(RmaDraft::class, RmaCancelled::class)
            ->allowTransition(RmaPending::class, RmaApproved::class)
            ->allowTransition(RmaPending::class, RmaRejected::class)
            ->allowTransition(RmaPending::class, RmaCancelled::class)
            ->allowTransition(RmaPending::class, RmaExpired::class)
            ->allowTransition(RmaApproved::class, RmaReceived::class)
            ->allowTransition(RmaApproved::class, RmaCancelled::class)
            ->allowTransition(RmaApproved::class, RmaExpired::class)
            ->allowTransition(RmaReceived::class, RmaCompleted::class);
    }
}
