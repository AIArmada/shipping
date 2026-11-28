<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeOverride;
use AIArmada\Shipping\Contracts\ZoneRateReader;
use AIArmada\Shipping\Contracts\ZoneResolutionStrategyInterface;
use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Data\PackageData;
use AIArmada\Shipping\Data\RateQuoteData;
use AIArmada\Shipping\Features\Rates\Models\ShippingRate;
use AIArmada\Shipping\Features\Rates\Models\ShippingZone;
use AIArmada\Shipping\Features\Rates\Strategies\GeoZoneResolutionStrategy;
use AIArmada\Shipping\Platform\ZoneResolutionStrategyRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves shipping zones and rates for addresses.
 */
class ShippingZoneResolver implements ZoneRateReader
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
     * @param  array<PackageData>  $packages
     * @return list<RateQuoteData>
     */
    public function quote(AddressData $destination, array $packages, int $cartTotal, int $itemCount, string $currency, ?string $ownerId = null, ?string $ownerType = null): array
    {
        $zone = $this->resolve($destination, $ownerId, $ownerType);

        if ($zone === null) {
            return [];
        }

        $quotes = [];

        foreach ($zone->rates()->active()->get() as $rate) {
            if (! $rate->meetsConditions($packages, $cartTotal, $itemCount)) {
                continue;
            }

            $quotes[] = new RateQuoteData(
                carrier: $rate->carrier_code ?? 'zone',
                service: $rate->method_code,
                rate: $rate->calculateRate($packages, $cartTotal),
                currency: $currency,
                estimatedDays: $rate->estimated_days_min ?? 3,
                serviceDescription: $rate->name,
                calculatedLocally: true,
                note: $rate->description,
            );
        }

        return $quotes;
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
        $matched = $strategy->resolve($address, $candidates->map(fn (ShippingZone $zone) => $zone->toCandidate())->values()->all());
        $byId = $candidates->keyBy('id');

        foreach ($matched as $id) {
            if ($byId->has($id)) {
                return $byId->get($id);
            }
        }

        return null;
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
            return $query;
        }

        return $query->forOwner(
            $this->resolveOwner($ownerId, $ownerType),
            includeGlobal: $this->includesGlobal(),
        );
    }

    private function buildCacheKey(AddressData $address, ?string $ownerId, ?string $ownerType): string
    {
        $this->assertCompleteOwnerTuple($ownerId, $ownerType);

        if (config('shipping.features.owner.enabled', false)) {
            $owner = $this->resolveOwner($ownerId, $ownerType);

            $ownerId = $owner?->getKey();
            $ownerType = $owner?->getMorphClass();
        } else {
            $ownerId = null;
            $ownerType = null;
        }

        return md5(serialize([
            'country' => $address->country,
            'state' => $address->state,
            'city' => $address->city,
            'postcode' => $address->postcode,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'owner_id' => $ownerId,
            'owner_type' => $ownerType,
            'owner_enabled' => (bool) config('shipping.features.owner.enabled', false),
            'include_global' => $this->includesGlobal(),
            'strategy' => config('shipping.zone_resolution.strategy', 'geo'),
        ]));
    }

    private function includesGlobal(): bool
    {
        return ! OwnerScopeOverride::suppressIncludeGlobal()
            && (bool) config('shipping.features.owner.include_global', false);
    }

    private function assertCompleteOwnerTuple(?string $ownerId, ?string $ownerType): void
    {
        if (($ownerId === null) !== ($ownerType === null)) {
            throw new InvalidArgumentException('Owner id and owner type must be provided together.');
        }
    }

    private function resolveOwner(?string $ownerId, ?string $ownerType): ?Model
    {
        $this->assertCompleteOwnerTuple($ownerId, $ownerType);

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            'Shipping zone resolution requires an owner context or explicit global context.',
        );

        if ($ownerId === null || $ownerType === null) {
            return $owner;
        }

        $explicit = OwnerContext::fromTypeAndIdOrFail($ownerType, $ownerId);

        if ($owner === null || $explicit === null
            || $owner->getMorphClass() !== $explicit->getMorphClass()
            || (string) $owner->getKey() !== (string) $explicit->getKey()) {
            throw new InvalidArgumentException('The explicit shipping owner must match the current owner context.');
        }

        return $owner;
    }
}
