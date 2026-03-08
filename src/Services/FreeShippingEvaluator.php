<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use InvalidArgumentException;

/**
 * Evaluates free shipping eligibility.
 *
 * This service can work with any cart-like object that provides subtotal information,
 * or directly with a subtotal amount.
 */
class FreeShippingEvaluator
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config = []
    ) {}

    /**
     * Evaluate free shipping for a given subtotal amount.
     *
     * @param  int|object  $subtotal  The cart subtotal in minor units, or an object with subtotal()/getMinorAmount() method
     */
    public function evaluate(int | object $subtotal): ?FreeShippingResult
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

        // Check if cart meets threshold
        if ($cartTotal >= $threshold) {
            return new FreeShippingResult(
                applies: true,
                message: 'Free shipping applied!',
            );
        }

        // Calculate remaining amount
        $remaining = $threshold - $cartTotal;

        return new FreeShippingResult(
            applies: false,
            nearThreshold: true,
            remainingAmount: $remaining,
            message: $this->formatRemainingMessage($remaining),
        );
    }

    /**
     * Resolve the subtotal from various input types.
     */
    protected function resolveSubtotal(int | object $subtotal): int
    {
        if (is_int($subtotal)) {
            return $subtotal;
        }

        // Handle Money-like objects with getMinorAmount() method (Brick\Money, etc.)
        if (method_exists($subtotal, 'getMinorAmount')) {
            $minorAmount = $subtotal->getMinorAmount();

            return method_exists($minorAmount, 'toInt')
                ? $minorAmount->toInt()
                : (int) $minorAmount;
        }

        // Handle cart-like objects with subtotal() method
        if (method_exists($subtotal, 'subtotal')) {
            $result = $subtotal->subtotal();

            if (is_int($result)) {
                return $result;
            }

            // Handle Money-like object returned from subtotal()
            if (is_object($result) && method_exists($result, 'getMinorAmount')) {
                $minorAmount = $result->getMinorAmount();

                return method_exists($minorAmount, 'toInt')
                    ? $minorAmount->toInt()
                    : (int) $minorAmount;
            }

            // Handle objects with getAmount() (older Money implementations)
            if (is_object($result) && method_exists($result, 'getAmount')) {
                return (int) $result->getAmount();
            }
        }

        throw new InvalidArgumentException(
            'FreeShippingEvaluator requires an integer (minor units), or object with subtotal()/getMinorAmount() method'
        );
    }

    /**
     * Format the remaining amount message.
     */
    protected function formatRemainingMessage(int $remaining): string
    {
        $formatted = number_format($remaining / 100, 2);
        $currency = $this->config['currency'] ?? 'RM';

        return "Add {$currency}{$formatted} more for free shipping!";
    }
}
