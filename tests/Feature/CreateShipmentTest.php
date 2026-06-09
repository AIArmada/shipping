<?php

declare(strict_types=1);

use AIArmada\Shipping\Actions\CreateShipment;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentItemData;
use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\Tests\TestCase;

uses(TestCase::class);

describe('CreateShipment', function (): void {
    it('creates a shipment from DTO', function (): void {
        $data = ShipmentData::from([
            'reference' => 'SHP-TEST-001',
            'carrierCode' => 'manual',
            'serviceCode' => 'standard',
            'origin' => AddressData::from([
                'name' => 'Warehouse',
                'phone' => '+60123456789',
                'line1' => '123 Storage St',
                'postcode' => '50000',
                'country' => 'MY',
                'city' => 'Kuala Lumpur',
                'state' => 'Kuala Lumpur',
            ]),
            'destination' => AddressData::from([
                'name' => 'John Buyer',
                'phone' => '+60198765432',
                'line1' => '456 Buyer Ave',
                'postcode' => '47800',
                'country' => 'MY',
                'city' => 'Petaling Jaya',
                'state' => 'Selangor',
            ]),
            'items' => [
                ShipmentItemData::from([
                    'name' => 'Widget A',
                    'sku' => 'WGT-001',
                    'quantity' => 2,
                    'weight' => 500,
                    'declaredValue' => 5000,
                ]),
            ],
            'declaredValue' => 10000,
            'currency' => 'MYR',
        ]);

        $shipment = CreateShipment::run($data);

        expect($shipment)->toBeInstanceOf(Shipment::class);
        expect($shipment->reference)->toBe('SHP-TEST-001');
        expect($shipment->carrier_code)->toBe('manual');
        expect($shipment->service_code)->toBe('standard');
        expect($shipment->total_weight)->toBe(1000);
        expect($shipment->declared_value)->toBe(10000);
        expect($shipment->items)->toHaveCount(1);
        expect($shipment->items->first()->sku)->toBe('WGT-001');
        expect($shipment->items->first()->quantity)->toBe(2);
        expect($shipment->items->first()->weight)->toBe(500);
    });

    it('creates shipment with empty items', function (): void {
        $data = ShipmentData::from([
            'reference' => 'SHP-EMPTY-ITEMS',
            'carrierCode' => 'manual',
            'serviceCode' => 'standard',
            'origin' => AddressData::from([
                'name' => 'Warehouse',
                'phone' => '+60123456789',
                'line1' => '123 Storage St',
                'postcode' => '50000',
                'country' => 'MY',
            ]),
            'destination' => AddressData::from([
                'name' => 'John Buyer',
                'phone' => '+60198765432',
                'line1' => '456 Buyer Ave',
                'postcode' => '47800',
                'country' => 'MY',
            ]),
        ]);

        $shipment = CreateShipment::run($data);

        expect($shipment)->toBeInstanceOf(Shipment::class);
        expect($shipment->reference)->toBe('SHP-EMPTY-ITEMS');
        expect($shipment->items)->toHaveCount(0);
        expect($shipment->total_weight)->toBe(0);
    });

    it('sets default currency when not provided', function (): void {
        $data = ShipmentData::from([
            'reference' => 'SHP-CURRENCY',
            'carrierCode' => 'manual',
            'serviceCode' => 'standard',
            'origin' => AddressData::from([
                'name' => 'Warehouse',
                'phone' => '+60123456789',
                'line1' => '123 Storage St',
                'postcode' => '50000',
                'country' => 'MY',
            ]),
            'destination' => AddressData::from([
                'name' => 'John Buyer',
                'phone' => '+60198765432',
                'line1' => '456 Buyer Ave',
                'postcode' => '47800',
                'country' => 'MY',
            ]),
            'currency' => null,
        ]);

        $shipment = CreateShipment::run($data);

        expect($shipment->currency)->toBe('MYR');
    });
});
