<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Cart;

use AIArmada\Cart\Conditions\CartCondition;
use AIArmada\Cart\Conditions\Enums\ConditionApplication;
use AIArmada\Cart\Conditions\Enums\ConditionPhase;
use AIArmada\Cart\Conditions\Enums\ConditionScope;

/**
 * Shipping condition applied to the cart.
 */
class ShippingCondition
{
    private CartCondition $condition;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        string $name,
        string $type,
        int | float | string $value,
        array $attributes = []
    ) {
        $this->condition = new CartCondition(
            name: $name,
            type: $type,
            target: $this->defaultTarget(),
            value: $value,
            attributes: $attributes,
            order: $attributes['order'] ?? 0,
        );
    }

    public function getCarrier(): ?string
    {
        return $this->condition->getAttribute('carrier');
    }

    public function getService(): ?string
    {
        return $this->condition->getAttribute('service');
    }

    public function getEstimatedDays(): ?int
    {
        return $this->condition->getAttribute('estimated_days');
    }

    public function getQuoteId(): ?string
    {
        return $this->condition->getAttribute('quote_id');
    }

    public function isFreeShipping(): bool
    {
        return $this->condition->getValue() === 0;
    }

    public function getFormattedValue(): string
    {
        if ($this->isFreeShipping()) {
            return 'FREE';
        }

        $value = $this->condition->getValue();
        $currency = $this->condition->getAttribute('currency') ?? 'MYR';

        return number_format($value / 100, 2) . ' ' . $currency;
    }

    public function asCartCondition(): CartCondition
    {
        return $this->condition;
    }

    /**
     * @return array<string, string>
     */
    private function defaultTarget(): array
    {
        return [
            'scope' => ConditionScope::CART->value,
            'phase' => ConditionPhase::SHIPPING->value,
            'application' => ConditionApplication::AGGREGATE->value,
        ];
    }
}
