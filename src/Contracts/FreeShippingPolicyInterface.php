<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Services\FreeShippingResult;

/**
 * Strategy for evaluating free shipping eligibility.
 *
 * Register implementations via FreeShippingPolicyRegistry
 * to support multiple free-shipping policies (threshold-based,
 * member-only, product-based, etc.).
 */
interface FreeShippingPolicyInterface
{
    /**
     * Get a unique key for this policy.
     */
    public function key(): string;

    /**
     * Evaluate free shipping for a given subtotal.
     *
     * @param  int|object  $subtotal  Cart subtotal in minor units, or object with subtotal()/getMinorAmount()
     * @param  array<string, mixed>  $context  Additional context (cart data, customer, etc.)
     */
    public function evaluate(int | object $subtotal, array $context = []): ?FreeShippingResult;
}
