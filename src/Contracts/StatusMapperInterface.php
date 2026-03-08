<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Enums\TrackingStatus;

/**
 * Contract for carrier-specific status mappers.
 *
 * Implementations translate carrier-specific event codes into
 * normalized tracking statuses.
 */
interface StatusMapperInterface
{
    /**
     * Map carrier-specific event code to normalized status.
     */
    public function map(string $carrierEventCode): TrackingStatus;

    /**
     * Get the carrier code this mapper handles.
     */
    public function getCarrierCode(): string;
}
