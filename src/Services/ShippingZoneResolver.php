<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Services;

use AIArmada\Shipping\Data\AddressData;
use AIArmada\Shipping\Models\ShippingRate;
use AIArmada\Shipping\Models\ShippingZone;
use AIArmada\Shipping\Support\ShippingOwnerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

        $zones = $query->get();

        foreach ($zones as $zone) {
            if ($zone->matchesAddress($address)) {
                return $zone;
            }
        }

        // Fall back to default zone
        return $zones->firstWhere('is_default', true);
    }

    /**
     * Build a cache key from address and owner parameters.
     */
    /**
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    private function applyOwnerScope(Builder $query, ?string $ownerId, ?string $ownerType): Builder
    {
        if (! ShippingOwnerScope::isEnabled()) {
            if ($ownerId !== null && $ownerType !== null) {
                $query->where('owner_id', $ownerId)
                    ->where('owner_type', $ownerType);
            }

            return $query;
        }

        // When explicit owner params are provided, use them directly (safe for non-HTTP contexts
        // such as queued jobs and console commands where ambient context is unavailable).
        if ($ownerId !== null && $ownerType !== null) {
            return $query->where(function (Builder $q) use ($ownerId, $ownerType): void {
                $q->where('owner_id', $ownerId)->where('owner_type', $ownerType);

                if (ShippingOwnerScope::includeGlobal()) {
                    $q->orWhereNull('owner_id');
                }
            });
        }

        // No explicit owner supplied — fall back to ambient context (HTTP requests via OwnerContext).
        return ShippingOwnerScope::applyToOwnedQuery($query);
    }

    private function buildCacheKey(AddressData $address, ?string $ownerId, ?string $ownerType): string
    {
        // When owner mode is enabled and no explicit owner was passed, resolve from ambient context.
        if (ShippingOwnerScope::isEnabled() && $ownerId === null && $ownerType === null) {
            $owner = ShippingOwnerScope::resolveOwner();

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
}
