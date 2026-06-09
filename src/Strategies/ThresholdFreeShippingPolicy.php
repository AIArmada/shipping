<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Strategies;

use AIArmada\CommerceSupport\Support\MoneyFormatter;
use AIArmada\Shipping\Contracts\FreeShippingPolicyInterface;
use AIArmada\Shipping\Services\FreeShippingResult;
use InvalidArgumentException;

/**
 * Threshold-based free shipping policy.
 *
 * Evaluates free shipping eligibility based on a configurable
 * subtotal threshold. This is the default policy.
 */
class ThresholdFreeShippingPolicy implements FreeShippingPolicyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config = [],
    ) {}

    public function key(): string
    {
        return 'threshold';
    }

    public function evaluate(int | object $subtotal, array $context = []): ?FreeShippingResult
    {
        $enabled = $this->config['enabled'] ?? false;

        if (! $enabled) {
            return null;
        }

        $threshold = $this->config['threshold'] ?? null;

        if ($threshold === null) {
            return null;
        }

        $cartTotal = $this->resolveSubtotal($subtotal);

        $currency = $this->config['currency'] ?? 'MYR';

        if ($cartTotal >= $threshold) {
            return new FreeShippingResult(
                applies: true,
                message: 'Free shipping applied!',
                currency: $currency,
            );
        }

        $remaining = $threshold - $cartTotal;

        return new FreeShippingResult(
            applies: false,
            nearThreshold: true,
            remainingAmount: $remaining,
            currency: $currency,
            message: $this->formatRemainingMessage($remaining, $currency),
        );
    }

    protected function resolveSubtotal(int | object $subtotal): int
    {
        if (is_int($subtotal)) {
            return $subtotal;
        }

        if (method_exists($subtotal, 'getMinorAmount')) {
            $minorAmount = $subtotal->getMinorAmount();

            return method_exists($minorAmount, 'toInt')
                ? $minorAmount->toInt()
                : (int) $minorAmount;
        }

        if (method_exists($subtotal, 'subtotal')) {
            $result = $subtotal->subtotal();

            if (is_int($result)) {
                return $result;
            }

            if (is_object($result) && method_exists($result, 'getMinorAmount')) {
                $minorAmount = $result->getMinorAmount();

                return method_exists($minorAmount, 'toInt')
                    ? $minorAmount->toInt()
                    : (int) $minorAmount;
            }

            if (is_object($result) && method_exists($result, 'getAmount')) {
                return (int) $result->getAmount();
            }
        }

        throw new InvalidArgumentException(
            'ThresholdFreeShippingPolicy requires an integer (minor units), or object with subtotal()/getMinorAmount() method'
        );
    }

    protected function formatRemainingMessage(int $remaining, string $currency): string
    {
        $formatted = MoneyFormatter::formatMinor($remaining, $currency);

        return "Add {$formatted} more for free shipping!";
    }
}
