<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerQuery;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Models\ShippingRate;
use AIArmada\Shipping\Models\ShippingZone;
use AIArmada\Shipping\Strategies\GeoZoneResolutionStrategy;
use AIArmada\Shipping\Support\ZoneResolutionStrategyRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves shipping zones and rates for addresses.
 */
class ShippingZoneResolver
{
    /**
     * Parameter-keyed cache for zone resolution.
     *
     * @var array<string, ShippingZone|null>
     */
    private array $resolvedZones = [];

    public function __construct(
        protected readonly ZoneResolutionStrategyRegistry $registry = new ZoneResolutionStrategyRegistry,
    ) {
        if ($this->registry->all() === []) {
            $this->registry->register(new GeoZoneResolutionStrategy);
        }
    }

    /**
     * Resolve the matching zone for an address.
     *
     * Results are cached for the request lifetime, keyed by address + owner.
     * This ensures different addresses within the same request get correct zones.
     */
    public function resolve(AddressData $address, ?string $ownerId = null, ?string $ownerType = null): ?ShippingZone
    {
        $cacheKey = $this->buildCacheKey($address, $ownerId, $ownerType);

        if (array_key_exists($cacheKey, $this->resolvedZones)) {
            return $this->resolvedZones[$cacheKey];
        }

        return $this->resolvedZones[$cacheKey] = $this->performZoneResolution($address, $ownerId, $ownerType);
    }

    /**
     * Clear the zone resolution cache.
     *
     * Useful for testing or when zone configuration changes mid-request.
     */
    public function clearCache(): void
    {
        $this->resolvedZones = [];
    }

    /**
     * Get all matching zones for an address (not just the first).
     *
     * @return Collection<int, ShippingZone>
     */
    public function resolveAll(AddressData $address, ?string $ownerId = null, ?string $ownerType = null): Collection
    {
        $query = ShippingZone::query()
            ->active()
            ->ordered();

        $query = $this->applyOwnerScope($query, $ownerId, $ownerType);

        return $query->get()
            ->filter(fn (ShippingZone $zone) => $zone->matchesAddress($address) || $zone->is_default);
    }

    /**
     * Get applicable rates for an address.
     *
     * @return Collection<int, ShippingRate>
     */
    public function getApplicableRates(
        AddressData $address,
        ?string $carrierCode = null,
        ?string $ownerId = null,
        ?string $ownerType = null
    ): Collection {
        $zone = $this->resolve($address, $ownerId, $ownerType);

        if ($zone === null) {
            return collect();
        }

        return $zone->rates()
            ->active()
            ->forCarrier($carrierCode)
            ->get();
    }

    /**
     * Check if an address is serviceable (has matching zone).
     */
    public function isServiceable(AddressData $address, ?string $ownerId = null, ?string $ownerType = null): bool
    {
        return $this->resolve($address, $ownerId, $ownerType) !== null;
    }

    /**
     * Test which zone an address matches (useful for debugging).
     *
     * @return array{matched: bool, zone: ?ShippingZone, reason: string}
     */
    public function test(AddressData $address, ?string $ownerId = null, ?string $ownerType = null): array
    {
        $zone = $this->resolve($address, $ownerId, $ownerType);

        if ($zone === null) {
            return [
                'matched' => false,
                'zone' => null,
                'reason' => 'No matching zone found for this address.',
            ];
        }

        $reason = $zone->is_default
            ? 'Matched to default zone.'
            : "Matched to zone '{$zone->name}' via {$zone->type} rule.";

        return [
            'matched' => true,
            'zone' => $zone,
            'reason' => $reason,
        ];
    }

    /**
     * Perform the actual zone resolution (uncached).
     */
    private function performZoneResolution(AddressData $address, ?string $ownerId, ?string $ownerType): ?ShippingZone
    {
        $query = ShippingZone::query()
            ->active()
            ->ordered();

        $query = $this->applyOwnerScope($query, $ownerId, $ownerType);

        $candidates = $query->get();

        $strategy = $this->resolveStrategy();
        $matched = $strategy->resolve($address, $candidates);

        return $matched->first();
    }

    private function resolveStrategy(): ZoneResolutionStrategyInterface
    {
        $key = config('shipping.zone_resolution.strategy', 'geo');

        return $this->registry->get($key);
    }

    /**
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    private function applyOwnerScope(Builder $query, ?string $ownerId, ?string $ownerType): Builder
    {
        $this->assertCompleteOwnerTuple($ownerId, $ownerType);

        if (! config('shipping.features.owner.enabled', false)) {
            if ($ownerId !== null && $ownerType !== null) {
                $query->where('owner_id', $ownerId)
                    ->where('owner_type', $ownerType);
            }

            return $query;
        }

        if ($ownerId !== null && $ownerType !== null) {
            $owner = OwnerContext::fromTypeAndId($ownerType, $ownerId);

            return OwnerQuery::applyToEloquentBuilder(
                $query->withoutGlobalScope(OwnerScope::class),
                $owner,
                (bool) config('shipping.features.owner.include_global', false),
            );
        }

        // No explicit owner supplied — fall back to ambient context (HTTP requests via OwnerContext).
        return $query;
    }

    private function buildCacheKey(AddressData $address, ?string $ownerId, ?string $ownerType): string
    {
        $this->assertCompleteOwnerTuple($ownerId, $ownerType);

        // When owner mode is enabled and no explicit owner was passed, resolve from ambient context.
        if (config('shipping.features.owner.enabled', false) && $ownerId === null && $ownerType === null) {
            $owner = OwnerContext::resolve();

            $ownerId = $owner?->getKey();
            $ownerType = $owner?->getMorphClass();
        }

        return md5(serialize([
            'country' => $address->country,
            'state' => $address->state,
            'city' => $address->city,
            'postcode' => $address->postcode,
            'owner_id' => $ownerId,
            'owner_type' => $ownerType,
        ]));
    }

    private function assertCompleteOwnerTuple(?string $ownerId, ?string $ownerType): void
    {
        if (($ownerId === null) !== ($ownerType === null)) {
            throw new InvalidArgumentException('Owner id and owner type must be provided together.');
        }
    }
}
