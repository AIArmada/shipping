<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Persistence;

use AIArmada\CommerceSupport\Support\OwnerScopeKey;
use AIArmada\Shipping\Data\RateQuoteData;
use BadMethodCallException;
use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class RateCache
{
    private const int GENERATION_TTL = 86400;

    private const string CONFIG_GENERATION_KEY = 'shipping:rates:configuration-generation';

    /**
     * @param  list<string>  $tags
     * @param  Closure(): array<int, RateQuoteData>  $load
     * @return array<int, RateQuoteData>
     */
    public function remember(array $tags, string $key, int $ttl, Closure $load): array
    {
        $repository = Cache::store();
        $configuration = $repository->get(self::CONFIG_GENERATION_KEY);

        if (! is_string($configuration)) {
            $repository->add(self::CONFIG_GENERATION_KEY, (string) Str::uuid(), self::GENERATION_TTL);
            $configuration = $repository->get(self::CONFIG_GENERATION_KEY);
        }

        if (! is_string($configuration)) {
            return $load();
        }

        $key .= ':' . $configuration;

        if ($repository->getStore() instanceof TaggableStore) {
            return $repository->tags($tags)->remember($key, $ttl, $load);
        }

        $generationKey = $this->generationKey($tags);
        $generation = $repository->get($generationKey);

        if (! is_string($generation)) {
            $repository->add($generationKey, (string) Str::uuid(), self::GENERATION_TTL);
            $generation = $repository->get($generationKey);
        }

        if (! is_string($generation)) {
            return $load();
        }

        return $repository->remember($key . ':' . $generation, $ttl, $load);
    }

    /** @param list<string> $tags */
    public function clear(array $tags): void
    {
        $repository = Cache::store();

        if ($repository->getStore() instanceof TaggableStore) {
            $repository->tags($tags)->flush();

            return;
        }

        $repository->put($this->generationKey($tags), (string) Str::uuid(), self::GENERATION_TTL);
    }

    public function invalidateConfiguration(): void
    {
        Cache::store()->put(self::CONFIG_GENERATION_KEY, (string) Str::uuid(), self::GENERATION_TTL);
    }

    public function invalidateOwner(?string $ownerType, string | int | null $ownerId): void
    {
        if ($ownerType === null && $ownerId === null) {
            $this->invalidateConfiguration();

            return;
        }

        $this->clear(['shipping-rates-owner-' . OwnerScopeKey::forTypeAndId($ownerType, $ownerId)]);
        $this->clear(['shipping', 'shipping:rates']);
    }

    /** @param list<string> $tags */
    private function generationKey(array $tags): string
    {
        return 'shipping:rates:generation:' . hash('sha256', implode('|', $tags));
    }

    public function forgetFailure(string $key): void
    {
        Cache::store()->forget($key);
    }

    public function recordFailure(string $key, int $ttl): void
    {
        $repository = Cache::store();

        try {
            Cache::lock($key . ':lock', 5)->block(2, function () use ($repository, $key, $ttl): void {
                $repository->put($key, (int) $repository->get($key, 0) + 1, $ttl);
            });
        } catch (BadMethodCallException | LockTimeoutException) {
            $repository->put($key, (int) $repository->get($key, 0) + 1, $ttl);
        }
    }

    public function failures(string $key): int
    {
        return (int) Cache::store()->get($key, 0);
    }
}
