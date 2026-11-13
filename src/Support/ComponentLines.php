<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Support;

use AIArmada\CommerceSupport\Support\CompositeComponents;

/**
 * Reads the shared cart/order `components` composition snapshot.
 *
 * @phpstan-import-type Component from CompositeComponents
 *
 * Shipping reads the frozen snapshot; it never re-resolves catalog components
 * after an order has been placed.
 */
final class ComponentLines
{
    /** @return list<Component> */
    public static function fromMixed(mixed $raw): array
    {
        return $raw === null ? [] : CompositeComponents::read($raw);
    }

    /**
     * @param  object  $item  Cart item exposing getAttribute()
     * @return list<Component>
     */
    public static function fromCartItem(object $item): array
    {
        if (! method_exists($item, 'getAttribute')) {
            return [];
        }

        return self::fromMixed($item->getAttribute('components'));
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return list<Component>
     */
    public static function fromOrderItemMetadata(?array $metadata): array
    {
        return self::fromMixed($metadata['components'] ?? null);
    }

    /**
     * @param  list<Component>  $components
     * @return list<Component>
     */
    public static function expand(array $components, int $parentQuantity): array
    {
        return CompositeComponents::expand($components, $parentQuantity);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public static function grams(array $values): ?int
    {
        foreach ($values as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $grams = (int) round((float) $value);

            if ($grams > 0) {
                return $grams;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @param  array<string, mixed>  $flat
     * @return array{length: int, width: int, height: int}|null
     */
    public static function dimensions(array $candidates, array $flat = []): ?array
    {
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $dimensions = self::normalizeDimensions([
                $candidate['length'] ?? null,
                $candidate['width'] ?? null,
                $candidate['height'] ?? null,
            ]);

            if ($dimensions !== null) {
                return $dimensions;
            }
        }

        return self::normalizeDimensions([
            $flat['length_cm'] ?? null,
            $flat['width_cm'] ?? null,
            $flat['height_cm'] ?? null,
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{length: int, width: int, height: int}|null
     */
    private static function normalizeDimensions(array $values): ?array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                return null;
            }

            $normalized[] = (int) round((float) $value);
        }

        if (count($normalized) !== 3 || in_array(0, $normalized, true)) {
            return null;
        }

        /** @var array{length: int, width: int, height: int} $dimensions */
        $dimensions = [
            'length' => $normalized[0],
            'width' => $normalized[1],
            'height' => $normalized[2],
        ];

        return $dimensions;
    }
}
