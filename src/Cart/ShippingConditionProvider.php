<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Cart;

use AIArmada\Cart\Cart;
use AIArmada\Cart\Conditions\CartCondition;
use AIArmada\Cart\Contracts\ConditionProviderInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Services\FreeShippingEvaluator;
use AIArmada\Shipping\Services\RateShoppingEngine;

/**
 * Provides shipping conditions for the cart.
 */
class ShippingConditionProvider implements ConditionProviderInterface
{
    private const string CONDITION_TYPE = 'shipping';

    private const int CONDITION_PRIORITY = 80;

    private const string SHIPPING_ADDRESS_KEY = 'shipping_address';

    private const string SELECTED_METHOD_KEY = 'selected_shipping_method';

    public function __construct(
        protected readonly RateShoppingEngine $rateEngine,
        protected readonly ?FreeShippingEvaluator $freeShippingEvaluator = null
    ) {}

    /**
     * Get shipping conditions for the cart.
     *
     * @return array<CartCondition>
     */
    public function getConditionsFor(Cart $cart): array
    {
        $destination = $this->getShippingAddress($cart);

        if ($destination === null) {
            return [];
        }

        $rate = $this->getSelectedRate($cart, $destination);

        if ($rate === null) {
            return [];
        }

        // Apply free shipping if qualified
        if ($this->freeShippingEvaluator !== null) {
            $freeResult = $this->freeShippingEvaluator->evaluate($cart);
            if ($freeResult !== null && $freeResult->applies) {
                $rate = $rate->withRate(0)->withNote('Free shipping applied');
            }
        }

        $condition = new ShippingCondition(
            name: $rate->service,
            type: self::CONDITION_TYPE,
            value: $rate->rate,
            attributes: [
                'carrier' => $rate->carrier,
                'service' => $rate->service,
                'estimated_days' => $rate->estimatedDays,
                'quote_id' => $rate->quoteId,
                'note' => $rate->note,
                'order' => self::CONDITION_PRIORITY,
            ],
        );

        return [$condition->asCartCondition()];
    }

    public function validate(CartCondition $condition, Cart $cart): bool
    {
        if ($condition->getType() !== self::CONDITION_TYPE) {
            return true;
        }

        return $this->getShippingAddress($cart) !== null;
    }

    public function getType(): string
    {
        return self::CONDITION_TYPE;
    }

    public function getPriority(): int
    {
        return self::CONDITION_PRIORITY;
    }

    /**
     * Get the selected or best shipping rate.
     */
    protected function getSelectedRate(Cart $cart, AddressData $destination): ?RateQuoteData
    {
        $selectedMethod = $cart->getMetadata(self::SELECTED_METHOD_KEY);

        if (! is_array($selectedMethod)) {
            $selectedMethod = null;
        }

        $origin = $this->getOriginAddress();
        $packages = $this->cartToPackages($cart);

        if ($selectedMethod !== null) {
            // Get specific rate that was selected
            $allRates = $this->rateEngine->getAllRates($origin, $destination, $packages);

            return $allRates->first(
                fn (RateQuoteData $rate) => $rate->carrier === $selectedMethod['carrier']
                && $rate->service === $selectedMethod['service']
            );
        }

        // Default to best rate based on strategy
        return $this->rateEngine->getBestRate($origin, $destination, $packages);
    }

    /**
     * Get shipping address from cart metadata.
     */
    protected function getShippingAddress(Cart $cart): ?AddressData
    {
        $addressData = $cart->getMetadata(self::SHIPPING_ADDRESS_KEY);

        if (! is_array($addressData)) {
            return null;
        }

        $name = $addressData['name'] ?? null;
        $phone = $addressData['phone'] ?? null;
        $line1 = $addressData['line1'] ?? null;
        $postcode = $addressData['postcode'] ?? $addressData['postCode'] ?? null;

        if (! is_string($name) || mb_trim($name) === '') {
            return null;
        }

        if (! is_string($phone) || mb_trim($phone) === '') {
            return null;
        }

        if (! is_string($line1) || mb_trim($line1) === '') {
            return null;
        }

        if (! is_string($postcode) || mb_trim($postcode) === '') {
            return null;
        }

        $country = $addressData['country'] ?? $addressData['countryCode'] ?? $addressData['country_code'] ?? 'MY';
        if (! is_string($country) || mb_trim($country) === '') {
            $country = 'MY';
        }

        $isResidential = $addressData['isResidential'] ?? $addressData['is_residential'] ?? true;
        if (! is_bool($isResidential)) {
            $isResidential = true;
        }

        return AddressData::from([
            'name' => $name,
            'phone' => $phone,
            'line1' => $line1,
            'postcode' => $postcode,
            'country' => $country,
            'company' => $addressData['company'] ?? null,
            'email' => $addressData['email'] ?? null,
            'line2' => $addressData['line2'] ?? null,
            'city' => $addressData['city'] ?? null,
            'state' => $addressData['state'] ?? null,
            'latitude' => $addressData['latitude'] ?? null,
            'longitude' => $addressData['longitude'] ?? null,
            'isResidential' => $isResidential,
        ]);
    }

    /**
     * Get the origin (sender) address from configuration.
     */
    protected function getOriginAddress(): AddressData
    {
        $origin = config('shipping.defaults.origin', []);

        return new AddressData(
            name: $origin['name'] ?? config('app.name', 'Store'),
            phone: $origin['phone'] ?? '',
            line1: $origin['line1'] ?? $origin['address'] ?? '',
            postcode: $origin['postcode'] ?? '',
            country: $origin['country'] ?? $origin['country_code'] ?? 'MY',
            state: $origin['state'] ?? null,
            city: $origin['city'] ?? null,
        );
    }

    /**
     * Convert cart items to package data.
     *
     * @return array<PackageData>
     */
    protected function cartToPackages(Cart $cart): array
    {
        $totalWeight = 0;
        $totalValue = 0;

        foreach ($cart->getItems() as $item) {
            $weight = $item->getAttribute('weight') ?? 0;
            $totalWeight += (int) ($weight * $item->quantity);
            // Use getRawSubtotal() to get the integer cents value
            $totalValue += $item->getRawSubtotal();
        }

        // For now, treat entire cart as single package
        return [
            new PackageData(
                weight: $totalWeight,
                declaredValue: $totalValue,
            ),
        ];
    }
}
