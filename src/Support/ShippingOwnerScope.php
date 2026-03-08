<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Support;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ShippingOwnerScope
{
    public static function isEnabled(): bool
    {
        return (bool) config('shipping.features.owner.enabled', false);
    }

    public static function includeGlobal(): bool
    {
        return (bool) config('shipping.features.owner.include_global', false);
    }

    public static function resolveOwner(): ?Model
    {
        if (! self::isEnabled()) {
            return null;
        }

        return OwnerContext::resolve();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyToOwnedQuery(Builder $query): Builder
    {
        if (! self::isEnabled()) {
            return $query;
        }

        $owner = self::resolveOwner();
        $includeGlobal = self::includeGlobal();

        if (method_exists($query->getModel(), 'scopeForOwner')) {
            /** @phpstan-ignore-next-line dynamic scope from HasOwner trait */
            return $query->forOwner($owner, $includeGlobal);
        }

        return OwnerQuery::applyToEloquentBuilder($query, $owner, $includeGlobal);
    }
}
