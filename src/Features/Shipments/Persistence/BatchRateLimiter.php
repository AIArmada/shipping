<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Persistence;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeKey;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Provides rate-limited batch execution for carrier API operations.
 *
 * Prevents overwhelming carrier APIs during bulk operations.
 */
final class BatchRateLimiter
{
    /**
     * Maximum operations per time window.
     */
    protected int $maxAttempts = 10;

    /**
     * Time window in seconds.
     */
    protected int $decaySeconds = 60;

    /**
     * Rate limiter key prefix.
     */
    protected string $keyPrefix = 'shipping';

    /**
     * Create a new instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Create a limiter configured for carrier operations.
     */
    public static function forCarrier(string $carrierCode): static
    {
        return static::make()
            ->keyPrefix("shipping:{$carrierCode}")
            ->maxAttempts(10)
            ->decaySeconds(60);
    }

    /**
     * Execute within the rate budget without blocking the request.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TResult
     *
     * @param  iterable<TKey, TValue>  $items
     * @param  Closure(TValue, TKey): TResult  $callback
     * @return array<TKey, array{success: bool, result?: TResult, error?: string}>
     */
    public function execute(iterable $items, Closure $callback, ?string $operationKey = null): array
    {
        $results = [];
        $key = $this->buildKey($operationKey);

        foreach ($items as $itemKey => $item) {
            if (RateLimiter::tooManyAttempts($key, $this->maxAttempts)
                || RateLimiter::hit($key, $this->decaySeconds) > $this->maxAttempts) {
                $retryAfter = RateLimiter::availableIn($key);
                $results[$itemKey] = [
                    'success' => false,
                    'error' => "Rate limit exceeded. Retry after {$retryAfter} seconds.",
                ];

                continue;
            }

            try {
                $results[$itemKey] = ['success' => true, 'result' => $callback($item, $itemKey)];
            } catch (Throwable $e) {
                $results[$itemKey] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Set maximum attempts per time window.
     */
    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    /**
     * Set time window in seconds.
     */
    public function decaySeconds(int $seconds): static
    {
        $this->decaySeconds = max(1, $seconds);

        return $this;
    }

    /**
     * Set rate limiter key prefix.
     */
    public function keyPrefix(string $prefix): static
    {
        $this->keyPrefix = $prefix;

        return $this;
    }

    /**
     * Build the rate limiter key.
     *
     * The owner scope segment keeps one tenant's bulk sync from consuming
     * another tenant's carrier rate budget.
     */
    protected function buildKey(?string $operation): string
    {
        $parts = [$this->keyPrefix, 'bulk'];

        if ($operation !== null) {
            $parts[] = $operation;
        }

        $parts[] = $this->ownerSegment();

        return implode(':', $parts);
    }

    protected function ownerSegment(): string
    {
        if (! (bool) config('shipping.features.owner.enabled', false)) {
            return OwnerScopeKey::GLOBAL;
        }

        $owner = OwnerContext::resolve();
        OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Shipment batch rate limiting requires an owner context.');

        return OwnerScopeKey::forOwner($owner);
    }
}
