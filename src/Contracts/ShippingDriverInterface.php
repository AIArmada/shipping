<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\LabelData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentResultData;
use AIArmada\Shipping\Data\ShippingMethodData;
use AIArmada\Shipping\Data\TrackingData;
use AIArmada\Shipping\Enums\DriverCapability;
use Illuminate\Support\Collection;

/**
 * Contract for shipping carrier drivers.
 *
 * All carrier implementations must implement this interface to integrate
 * with the unified shipping management layer.
 */
interface ShippingDriverInterface
{
    /**
     * Get unique carrier identifier.
     */
    public function getCarrierCode(): string;

    /**
     * Get human-readable carrier name.
     */
    public function getCarrierName(): string;

    /**
     * Check if carrier supports a specific capability.
     */
    public function supports(DriverCapability $capability): bool;

    /**
     * Get available shipping methods for this carrier.
     *
     * @return Collection<int, ShippingMethodData>
     */
    public function getAvailableMethods(): Collection;

    /**
     * Get rate quotes for a shipment.
     *
     * @param  array<PackageData>  $packages
     * @param  array<string, mixed>  $options
     * @return Collection<int, RateQuoteData>
     */
    public function getRates(
        AddressData $origin,
        AddressData $destination,
        array $packages,
        array $options = []
    ): Collection;

    /**
     * Create a shipment with the carrier.
     */
    public function createShipment(ShipmentData $data): ShipmentResultData;

    /**
     * Cancel a shipment.
     */
    public function cancelShipment(string $trackingNumber): bool;

    /**
     * Generate shipping label.
     *
     * @param  array<string, mixed>  $options
     */
    public function generateLabel(string $trackingNumber, array $options = []): LabelData;

    /**
     * Track a shipment.
     */
    public function track(string $trackingNumber): TrackingData;

    /**
     * Validate an address.
     */
    public function validateAddress(AddressData $address): AddressValidationResult;

    /**
     * Check if carrier services this destination.
     */
    public function servicesDestination(AddressData $destination): bool;
}
