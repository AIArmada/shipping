<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provides retry logic with exponential backoff for carrier API calls.
 */
final class RetryService
{
    /**
     * Default maximum retry attempts.
     */
    protected int $maxAttempts;

    /**
     * Base delay between retries in milliseconds.
     */
    protected int $baseDelayMs;

    /**
     * Create a new RetryService instance.
     */
    public function __construct()
    {
        $this->maxAttempts = (int) config('shipping.api_retries', 3);
        $this->baseDelayMs = (int) config('shipping.api_base_delay_ms', 100);
    }

    /**
     * Maximum delay between retries in milliseconds.
     */
    protected int $maxDelayMs = 10000;

    /**
     * Multiplier for exponential backoff.
     */
    protected float $multiplier = 2.0;

    /**
     * Whether to add jitter to delay.
     */
    protected bool $useJitter = true;

    /**
     * Create a new instance with default configuration.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Execute a callback with retry logic.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  array<class-string<Throwable>>  $retryOn  Exceptions to retry on
     * @return T
     *
     * @throws Throwable
     */
    public function execute(Closure $callback, array $retryOn = [], ?string $context = null): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->maxAttempts) {
            $attempts++;

            try {
                return $callback();
            } catch (Throwable $e) {
                $lastException = $e;

                // Check if we should retry for this exception type
                if (! $this->shouldRetry($e, $retryOn)) {
                    throw $e;
                }

                // Don't retry on last attempt
                if ($attempts >= $this->maxAttempts) {
                    break;
                }

                $delay = $this->calculateDelay($attempts);

                $this->log('warning', 'Retry attempt for carrier API call', [
                    'context' => $context,
                    'attempt' => $attempts,
                    'max_attempts' => $this->maxAttempts,
                    'delay_ms' => $delay,
                    'exception' => $e->getMessage(),
                ]);

                // Sleep before retry
                usleep($delay * 1000);
            }
        }

        $this->log('error', 'All retry attempts exhausted for carrier API call', [
            'context' => $context,
            'attempts' => $attempts,
            'exception' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new Exception('Retry failed without exception');
    }

    /**
     * Set maximum retry attempts.
     */
    public function attempts(int $attempts): static
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    /**
     * Set base delay between retries.
     */
    public function delay(int $delayMs): static
    {
        $this->baseDelayMs = max(0, $delayMs);

        return $this;
    }

    /**
     * Set maximum delay (cap for exponential growth).
     */
    public function maxDelay(int $delayMs): static
    {
        $this->maxDelayMs = max($this->baseDelayMs, $delayMs);

        return $this;
    }

    /**
     * Set the backoff multiplier.
     */
    public function backoff(float $multiplier): static
    {
        $this->multiplier = max(1.0, $multiplier);

        return $this;
    }

    /**
     * Enable or disable jitter.
     */
    public function withJitter(bool $enabled = true): static
    {
        $this->useJitter = $enabled;

        return $this;
    }

    /**
     * Log a message if the logger is available.
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        // Only log if app container is available
        if (function_exists('app') && app()->bound('log')) {
            Log::{$level}($message, $context);
        }
    }

    /**
     * Determine if we should retry for the given exception.
     *
     * @param  array<class-string<Throwable>>  $retryOn
     */
    protected function shouldRetry(Throwable $exception, array $retryOn): bool
    {
        // If no specific exceptions specified, retry on all
        if (empty($retryOn)) {
            return $this->isRetryableException($exception);
        }

        foreach ($retryOn as $exceptionClass) {
            if ($exception instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the exception is a transient/retryable error.
     */
    protected function isRetryableException(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        // Common transient error patterns
        $transientPatterns = [
            'timeout',
            'timed out',
            'connection refused',
            'connection reset',
            'temporarily unavailable',
            'service unavailable',
            'too many requests',
            'rate limit',
            'gateway timeout',
            'bad gateway',
            '502',
            '503',
            '504',
            '429',
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate delay with exponential backoff and optional jitter.
     */
    protected function calculateDelay(int $attempt): int
    {
        // Exponential backoff: baseDelay * (multiplier ^ attempt)
        $delay = (int) ($this->baseDelayMs * pow($this->multiplier, $attempt - 1));

        // Cap at maximum delay
        $delay = min($delay, $this->maxDelayMs);

        // Add jitter to prevent thundering herd
        if ($this->useJitter) {
            $jitter = random_int(0, (int) ($delay * 0.3));
            $delay += $jitter;
        }

        return $delay;
    }
}
