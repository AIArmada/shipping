<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Cart;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\ShipmentData;
use AIArmada\Shipping\Data\ShipmentItemData;
use Throwable;

class CartBridge
{
    public function createShipmentDataFromOrder(array $orderData): ShipmentData
    {
        $originAddress = $this->getDefaultOriginAddress();
        $destinationAddress = $this->parseAddress($orderData['shipping_address']);
        $items = $this->parseItems($orderData['items']);

        return new ShipmentData(
            reference: $orderData['reference'] ?? $orderData['id'],
            carrierCode: $orderData['carrier_code'] ?? 'manual',
            serviceCode: $orderData['service_code'] ?? 'standard',
            origin: $originAddress,
            destination: $destinationAddress,
            items: $items,
            declaredValue: $orderData['declared_value'] ?? $this->calculateDeclaredValue($items),
            instructions: $orderData['instructions'] ?? null,
            codAmount: $orderData['cod_amount'] ?? null,
        );
    }

    public function getOrderUrl(string $orderId): ?string
    {
        $resourceClass = 'AIArmada\\FilamentOrders\\Resources\\OrderResource';

        if (! class_exists($resourceClass)) {
            return null;
        }

        try {
            return $resourceClass::getUrl('view', ['record' => $orderId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getCreateShipmentUrl(string $orderId): string
    {
        return route('filament.admin.resources.shipments.create', [
            'order_id' => $orderId,
        ]);
    }

    public function isCartPackageInstalled(): bool
    {
        return class_exists('AIArmada\\Cart\\Cart');
    }

    protected function getDefaultOriginAddress(): AddressData
    {
        $config = config('shipping.defaults.origin', []);

        return new AddressData(
            name: $config['name'] ?? config('app.name', 'Warehouse'),
            phone: $config['phone'] ?? '',
            line1: $config['line1'] ?? '',
            line2: $config['line2'] ?? null,
            city: $config['city'] ?? null,
            state: $config['state'] ?? null,
            postcode: $config['postcode'] ?? '',
            country: $config['country'] ?? $config['country_code'] ?? 'MY',
        );
    }

    protected function parseAddress(array $address): AddressData
    {
        return new AddressData(
            name: $address['name'],
            phone: $address['phone'],
            line1: $address['line1'] ?? '',
            line2: $address['line2'] ?? null,
            city: $address['city'] ?? null,
            state: $address['state'] ?? null,
            postcode: $address['postcode'] ?? '',
            country: $address['country'] ?? $address['country_code'] ?? 'MY',
        );
    }

    protected function parseItems(array $items): array
    {
        return array_map(function ($item) {
            return new ShipmentItemData(
                name: $item['name'],
                sku: $item['sku'] ?? null,
                quantity: $item['quantity'],
                weight: $item['weight'] ?? null,
                declaredValue: $item['declared_value'] ?? null,
            );
        }, $items);
    }

    protected function calculateDeclaredValue(array $items): int
    {
        return array_reduce($items, function (int $total, ShipmentItemData $item) {
            return $total + (($item->declaredValue ?? 0) * $item->quantity);
        }, 0);
    }
}
