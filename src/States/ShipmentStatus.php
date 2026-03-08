<?php

declare(strict_types=1);

namespace AIArmada\Shipping\States;

use AIArmada\Shipping\Enums\ShipmentStatus as ShipmentStatusEnum;
use AIArmada\Shipping\Enums\TrackingStatus;
use AIArmada\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @method Shipment getModel()
 */
abstract class ShipmentStatus extends State
{
    abstract public function label(): string;

    abstract public function color(): string;

    abstract public function icon(): string;

    public function isPending(): bool
    {
        return false;
    }

    public function isInTransit(): bool
    {
        return false;
    }

    public function isDelivered(): bool
    {
        return false;
    }

    public function isCancellable(): bool
    {
        return false;
    }

    public function isTerminal(): bool
    {
        return false;
    }

    public function toTrackingStatus(): TrackingStatus
    {
        return match (true) {
            $this instanceof Draft => TrackingStatus::LabelCreated,
            $this instanceof Pending => TrackingStatus::AwaitingPickup,
            $this instanceof AwaitingPickup => TrackingStatus::AwaitingPickup,
            $this instanceof Shipped => TrackingStatus::PickedUp,
            $this instanceof InTransit => TrackingStatus::InTransit,
            $this instanceof OutForDelivery => TrackingStatus::OutForDelivery,
            $this instanceof Delivered => TrackingStatus::Delivered,
            $this instanceof DeliveryFailed => TrackingStatus::DeliveryAttemptFailed,
            $this instanceof ReturnToSender => TrackingStatus::ReturnToSender,
            $this instanceof Cancelled => TrackingStatus::OnHold,
            $this instanceof OnHold => TrackingStatus::OnHold,
            $this instanceof ExceptionStatus => TrackingStatus::Delayed,
            default => TrackingStatus::InTransit,
        };
    }

    public static function normalize(ShipmentStatusEnum | ShipmentStatus | string $status): string
    {
        if ($status instanceof ShipmentStatus) {
            return $status->getValue();
        }

        if ($status instanceof ShipmentStatusEnum) {
            return $status->value;
        }

        if (class_exists($status) && is_subclass_of($status, ShipmentStatus::class)) {
            return $status::getMorphClass();
        }

        return $status;
    }

    /**
     * @return array<string, string>
     */
    public static function options(?Model $model = null): array
    {
        $model ??= new Shipment;

        $options = [];

        /** @var class-string<ShipmentStatus> $stateClass */
        foreach (self::all()->all() as $stateClass) {
            $state = new $stateClass($model);
            $options[$state->getValue()] = $state->label();
        }

        return $options;
    }

    public static function fromString(ShipmentStatusEnum | ShipmentStatus | string $status, ?Model $model = null): ShipmentStatus
    {
        if ($status instanceof ShipmentStatus) {
            return $status;
        }

        $model ??= new Shipment;
        $stateClass = self::resolveStateClassFor($status, $model);

        return new $stateClass($model);
    }

    /**
     * @return class-string<ShipmentStatus>
     */
    public static function resolveStateClassFor(ShipmentStatusEnum | ShipmentStatus | string $status, ?Model $model = null): string
    {
        if ($status instanceof ShipmentStatus) {
            return $status::class;
        }

        if ($status instanceof ShipmentStatusEnum) {
            $status = $status->value;
        }

        if (class_exists($status) && is_subclass_of($status, ShipmentStatus::class)) {
            return $status;
        }

        $model ??= new Shipment;

        /** @var class-string<ShipmentStatus> $stateClass */
        foreach (self::all()->all() as $stateClass) {
            $state = new $stateClass($model);
            if ($state->getValue() === $status) {
                return $stateClass;
            }
        }

        return Draft::class;
    }

    final public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Pending::class)
            ->allowTransition(Draft::class, Cancelled::class)
            ->allowTransition(Pending::class, AwaitingPickup::class)
            ->allowTransition(Pending::class, Shipped::class)
            ->allowTransition(Pending::class, Cancelled::class)
            ->allowTransition(AwaitingPickup::class, Shipped::class)
            ->allowTransition(AwaitingPickup::class, Cancelled::class)
            ->allowTransition(Shipped::class, InTransit::class)
            ->allowTransition(Shipped::class, ExceptionStatus::class)
            ->allowTransition(InTransit::class, OutForDelivery::class)
            ->allowTransition(InTransit::class, Delivered::class)
            ->allowTransition(InTransit::class, DeliveryFailed::class)
            ->allowTransition(InTransit::class, ExceptionStatus::class)
            ->allowTransition(InTransit::class, OnHold::class)
            ->allowTransition(OutForDelivery::class, Delivered::class)
            ->allowTransition(OutForDelivery::class, DeliveryFailed::class)
            ->allowTransition(OutForDelivery::class, ExceptionStatus::class)
            ->allowTransition(DeliveryFailed::class, InTransit::class)
            ->allowTransition(DeliveryFailed::class, OutForDelivery::class)
            ->allowTransition(DeliveryFailed::class, ReturnToSender::class)
            ->allowTransition(ExceptionStatus::class, InTransit::class)
            ->allowTransition(ExceptionStatus::class, OnHold::class)
            ->allowTransition(ExceptionStatus::class, ReturnToSender::class)
            ->allowTransition(OnHold::class, InTransit::class)
            ->allowTransition(OnHold::class, Cancelled::class);
    }
}
