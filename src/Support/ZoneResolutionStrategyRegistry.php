<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Support;

use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use InvalidArgumentException;

final class ZoneResolutionStrategyRegistry
{
    /**
     * @var array<string, ZoneResolutionStrategyInterface>
     */
    private array $strategies = [];

    public function register(ZoneResolutionStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->key()] = $strategy;
    }

    public function get(string $key): ZoneResolutionStrategyInterface
    {
        if (! isset($this->strategies[$key])) {
            throw new InvalidArgumentException(sprintf(
                'No zone resolution strategy registered for key [%s].',
                $key,
            ));
        }

        return $this->strategies[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /**
     * @return array<string, ZoneResolutionStrategyInterface>
     */
    public function all(): array
    {
        return $this->strategies;
    }
}
