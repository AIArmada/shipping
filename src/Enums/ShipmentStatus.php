<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Enums;

/**
 * Shipment status workflow.
 */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case AwaitingPickup = 'awaiting_pickup';
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case ReturnToSender = 'return_to_sender';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';
    case Exception = 'exception';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::AwaitingPickup => 'Awaiting Pickup',
            self::Shipped => 'Shipped',
            self::InTransit => 'In Transit',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery Failed',
            self::ReturnToSender => 'Return to Sender',
            self::Cancelled => 'Cancelled',
            self::OnHold => 'On Hold',
            self::Exception => 'Exception',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'yellow',
            self::AwaitingPickup => 'blue',
            self::Shipped => 'indigo',
            self::InTransit => 'blue',
            self::OutForDelivery => 'cyan',
            self::Delivered => 'green',
            self::DeliveryFailed => 'red',
            self::ReturnToSender => 'orange',
            self::Cancelled => 'gray',
            self::OnHold => 'yellow',
            self::Exception => 'red',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-document',
            self::Pending => 'heroicon-o-clock',
            self::AwaitingPickup => 'heroicon-o-truck',
            self::Shipped => 'heroicon-o-paper-airplane',
            self::InTransit => 'heroicon-o-truck',
            self::OutForDelivery => 'heroicon-o-map-pin',
            self::Delivered => 'heroicon-o-check-circle',
            self::DeliveryFailed => 'heroicon-o-x-circle',
            self::ReturnToSender => 'heroicon-o-arrow-uturn-left',
            self::Cancelled => 'heroicon-o-x-mark',
            self::OnHold => 'heroicon-o-pause-circle',
            self::Exception => 'heroicon-o-exclamation-triangle',
        };
    }

    /**
     * Get valid transitions from this status.
     *
     * @return array<self>
     */
    public function getAllowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],
            self::Pending => [self::AwaitingPickup, self::Shipped, self::Cancelled],
            self::AwaitingPickup => [self::Shipped, self::Cancelled],
            self::Shipped => [self::InTransit, self::Exception],
            self::InTransit => [self::OutForDelivery, self::Delivered, self::DeliveryFailed, self::Exception, self::OnHold],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed, self::Exception],
            self::DeliveryFailed => [self::InTransit, self::OutForDelivery, self::ReturnToSender],
            self::Exception => [self::InTransit, self::OnHold, self::ReturnToSender],
            self::OnHold => [self::InTransit, self::Cancelled],
            default => [],
        };
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->getAllowedTransitions(), true);
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isInTransit(): bool
    {
        return in_array($this, [
            self::Shipped,
            self::InTransit,
            self::OutForDelivery,
        ], true);
    }

    public function isDelivered(): bool
    {
        return $this === self::Delivered;
    }

    public function isCancellable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Pending,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::Cancelled,
            self::ReturnToSender,
        ], true);
    }

    /**
     * Convert this shipment status to a normalized tracking status.
     */
    public function toTrackingStatus(): TrackingStatus
    {
        return match ($this) {
            self::Draft => TrackingStatus::LabelCreated,
            self::Pending => TrackingStatus::AwaitingPickup,
            self::AwaitingPickup => TrackingStatus::AwaitingPickup,
            self::Shipped => TrackingStatus::PickedUp,
            self::InTransit => TrackingStatus::InTransit,
            self::OutForDelivery => TrackingStatus::OutForDelivery,
            self::Delivered => TrackingStatus::Delivered,
            self::DeliveryFailed => TrackingStatus::DeliveryAttemptFailed,
            self::ReturnToSender => TrackingStatus::ReturnToSender,
            self::Cancelled => TrackingStatus::OnHold,
            self::OnHold => TrackingStatus::OnHold,
            self::Exception => TrackingStatus::Delayed,
        };
    }
}
