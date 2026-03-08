<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Enums;

/**
 * Normalized tracking statuses across all carriers.
 */
enum TrackingStatus: string
{
    // Pre-shipment
    case LabelCreated = 'label_created';
    case AwaitingPickup = 'awaiting_pickup';
    case PickedUp = 'picked_up';

    // In Transit
    case InTransit = 'in_transit';
    case ArrivedAtFacility = 'arrived_at_facility';
    case DepartedFacility = 'departed_facility';
    case InCustoms = 'in_customs';
    case CustomsCleared = 'customs_cleared';

    // Out for Delivery
    case OutForDelivery = 'out_for_delivery';

    // Delivered
    case Delivered = 'delivered';
    case DeliveredToNeighbor = 'delivered_to_neighbor';
    case DeliveredToLocker = 'delivered_to_locker';
    case SignedFor = 'signed_for';

    // Exceptions
    case DeliveryAttemptFailed = 'delivery_attempt_failed';
    case AddressIssue = 'address_issue';
    case CustomerRefused = 'customer_refused';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Delayed = 'delayed';
    case OnHold = 'on_hold';

    // Returns
    case ReturnToSender = 'return_to_sender';
    case ReturnInTransit = 'return_in_transit';
    case ReturnDelivered = 'return_delivered';

    public function getLabel(): string
    {
        return match ($this) {
            self::LabelCreated => 'Label Created',
            self::AwaitingPickup => 'Awaiting Pickup',
            self::PickedUp => 'Picked Up',
            self::InTransit => 'In Transit',
            self::ArrivedAtFacility => 'Arrived at Facility',
            self::DepartedFacility => 'Departed Facility',
            self::InCustoms => 'In Customs',
            self::CustomsCleared => 'Customs Cleared',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::DeliveredToNeighbor => 'Delivered to Neighbor',
            self::DeliveredToLocker => 'Delivered to Locker',
            self::SignedFor => 'Signed For',
            self::DeliveryAttemptFailed => 'Delivery Attempt Failed',
            self::AddressIssue => 'Address Issue',
            self::CustomerRefused => 'Customer Refused',
            self::Damaged => 'Damaged',
            self::Lost => 'Lost',
            self::Delayed => 'Delayed',
            self::OnHold => 'On Hold',
            self::ReturnToSender => 'Return to Sender',
            self::ReturnInTransit => 'Return In Transit',
            self::ReturnDelivered => 'Return Delivered',
        };
    }

    public function getCategory(): string
    {
        return match ($this) {
            self::LabelCreated, self::AwaitingPickup, self::PickedUp => 'pre_shipment',
            self::InTransit, self::ArrivedAtFacility, self::DepartedFacility,
            self::InCustoms, self::CustomsCleared => 'in_transit',
            self::OutForDelivery => 'out_for_delivery',
            self::Delivered, self::DeliveredToNeighbor, self::DeliveredToLocker,
            self::SignedFor => 'delivered',
            self::DeliveryAttemptFailed, self::AddressIssue, self::CustomerRefused,
            self::Damaged, self::Lost, self::Delayed, self::OnHold => 'exception',
            self::ReturnToSender, self::ReturnInTransit, self::ReturnDelivered => 'return',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::DeliveredToNeighbor,
            self::DeliveredToLocker,
            self::SignedFor,
            self::Lost,
            self::ReturnDelivered,
        ], true);
    }

    public function isException(): bool
    {
        return $this->getCategory() === 'exception';
    }

    public function getIcon(): string
    {
        return match ($this->getCategory()) {
            'pre_shipment' => 'heroicon-o-clock',
            'in_transit' => 'heroicon-o-truck',
            'out_for_delivery' => 'heroicon-o-map-pin',
            'delivered' => 'heroicon-o-check-circle',
            'exception' => 'heroicon-o-exclamation-triangle',
            'return' => 'heroicon-o-arrow-uturn-left',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this->getCategory()) {
            'pre_shipment' => 'gray',
            'in_transit' => 'blue',
            'out_for_delivery' => 'indigo',
            'delivered' => 'green',
            'exception' => 'red',
            'return' => 'orange',
            default => 'gray',
        };
    }
}
