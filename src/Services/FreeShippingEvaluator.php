<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\Shipping\Contracts\FreeShippingPolicyInterface;
use AIArmada\Shipping\Strategies\ThresholdFreeShippingPolicy;
use AIArmada\Shipping\Support\FreeShippingPolicyRegistry;

/**
 * Evaluates free shipping eligibility using registered policies.
 *
 * Delegates to the FreeShippingPolicyRegistry which holds one or more
 * FreeShippingPolicyInterface implementations. The default threshold-based
 * policy is registered automatically; merchants can add custom policies.
 */
class FreeShippingEvaluator
{
    protected FreeShippingPolicyRegistry $registry;

    protected array $config = [];

    public function __construct(
        FreeShippingPolicyRegistry | array $registry = [],
        array $config = [],
    ) {
        if ($registry instanceof FreeShippingPolicyRegistry) {
            $this->registry = $registry;
            $this->config = $config;
        } else {
            $this->config = $registry;
            $this->registry = new FreeShippingPolicyRegistry;
        }

        if ($this->registry->all() === []) {
            $this->registry->register(new ThresholdFreeShippingPolicy($this->config));
        }
    }

    public function evaluate(int | object $subtotal, array $context = []): ?FreeShippingResult
    {
        $policy = $this->resolvePolicy();

        if ($policy === null) {
            return null;
        }

        return $policy->evaluate($subtotal, $context);
    }

    /**
     * Resolve the active free shipping policy.
     */
    protected function resolvePolicy(): ?FreeShippingPolicyInterface
    {
        $policyKey = $this->config['policy'] ?? 'threshold';

        if (! $this->registry->has($policyKey)) {
            return null;
        }

        return $this->registry->get($policyKey);
    }
}
