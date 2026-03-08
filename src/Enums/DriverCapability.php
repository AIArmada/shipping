<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Enums;

/**
 * Driver capabilities that a shipping carrier may support.
 */
enum DriverCapability: string
{
    case RateQuotes = 'rate_quotes';
    case LabelGeneration = 'label_generation';
    case Tracking = 'tracking';
    case Webhooks = 'webhooks';
    case Returns = 'returns';
    case AddressValidation = 'address_validation';
    case PickupScheduling = 'pickup_scheduling';
    case CashOnDelivery = 'cash_on_delivery';
    case BatchOperations = 'batch_operations';
    case InsuranceClaims = 'insurance_claims';
    case MultiPackage = 'multi_package';
    case InternationalShipping = 'international_shipping';
}
