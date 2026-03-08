<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
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
     * Delay between batches in milliseconds.
     */
    protected int $batchDelayMs = 100;

    /**
     * Batch size for processing.
     */
    protected int $batchSize = 5;

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
            ->decaySeconds(60)
            ->batchSize(5)
            ->batchDelay(200);
    }

    /**
     * Execute operations in rate-limited batches.
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
        $batch = [];
        $batchIndex = 0;
        $key = $this->buildKey($operationKey);

        foreach ($items as $itemKey => $item) {
            $batch[$itemKey] = $item;

            if (count($batch) >= $this->batchSize) {
                $this->processBatch($batch, $callback, $results, $key);
                $batch = [];

                // Delay between batches to prevent overwhelming (skip first batch)
                if ($batchIndex > 0) {
                    usleep($this->batchDelayMs * 1000);
                }
                $batchIndex++;
            }
        }

        // Process remaining items
        if (! empty($batch)) {
            $this->processBatch($batch, $callback, $results, $key);
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
     * Set delay between batches in milliseconds.
     */
    public function batchDelay(int $ms): static
    {
        $this->batchDelayMs = max(0, $ms);

        return $this;
    }

    /**
     * Set batch size.
     */
    public function batchSize(int $size): static
    {
        $this->batchSize = max(1, $size);

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
     * Process a batch of items with rate limiting.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TResult
     *
     * @param  array<TKey, TValue>  $batch
     * @param  Closure(TValue, TKey): TResult  $callback
     * @param  array<TKey, array{success: bool, result?: TResult, error?: string}>  $results
     */
    protected function processBatch(array $batch, Closure $callback, array &$results, string $key): void
    {
        foreach ($batch as $itemKey => $item) {
            // Wait for rate limit if needed
            $this->waitForRateLimit($key);

            try {
                $result = $callback($item, $itemKey);
                $results[$itemKey] = [
                    'success' => true,
                    'result' => $result,
                ];
            } catch (Throwable $e) {
                $results[$itemKey] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    /**
     * Wait for rate limit to clear if exceeded.
     */
    protected function waitForRateLimit(string $key): void
    {
        $maxWaitSeconds = 30;
        $waited = 0;

        while (RateLimiter::tooManyAttempts($key, $this->maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            if ($waited + $retryAfter > $maxWaitSeconds) {
                throw new RuntimeException(
                    "Rate limit exceeded. Would need to wait {$retryAfter} seconds."
                );
            }

            sleep($retryAfter);
            $waited += $retryAfter;
        }

        RateLimiter::hit($key, $this->decaySeconds);
    }

    /**
     * Build the rate limiter key.
     */
    protected function buildKey(?string $operation): string
    {
        $parts = [$this->keyPrefix, 'bulk'];

        if ($operation !== null) {
            $parts[] = $operation;
        }

        return implode(':', $parts);
    }
}
