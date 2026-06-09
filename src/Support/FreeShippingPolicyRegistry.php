<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Support;

use AIArmada\Shipping\Contracts\FreeShippingPolicyInterface;
use InvalidArgumentException;

final class FreeShippingPolicyRegistry
{
    /**
     * @var array<string, FreeShippingPolicyInterface>
     */
    private array $policies = [];

    public function register(FreeShippingPolicyInterface $policy): void
    {
        $this->policies[$policy->key()] = $policy;
    }

    public function get(string $key): FreeShippingPolicyInterface
    {
        if (! isset($this->policies[$key])) {
            throw new InvalidArgumentException(sprintf(
                'No free shipping policy registered for key [%s].',
                $key,
            ));
        }

        return $this->policies[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->policies[$key]);
    }

    /**
     * @return array<string, FreeShippingPolicyInterface>
     */
    public function all(): array
    {
        return $this->policies;
    }
}
