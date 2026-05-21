<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\Shipping\Data\PackageData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $zone_id
 * @property string|null $carrier_code
 * @property string $method_code
 * @property string $name
 * @property string|null $description
 * @property string $calculation_type
 * @property int $base_rate
 * @property int $per_unit_rate
 * @property int|null $min_charge
 * @property int|null $max_charge
 * @property int|null $free_shipping_threshold
 * @property array|null $rate_table
 * @property int|null $estimated_days_min
 * @property int|null $estimated_days_max
 * @property array|null $conditions
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ShippingZone $zone
 * @property-read string $formatted_base_rate
 */
class ShippingRate extends Model
{
    use FormatsMoney;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'zone_id',
        'carrier_code',
        'method_code',
        'name',
        'description',
        'calculation_type',
        'base_rate',
        'per_unit_rate',
        'min_charge',
        'max_charge',
        'free_shipping_threshold',
        'rate_table',
        'estimated_days_min',
        'estimated_days_max',
        'conditions',
        'active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'base_rate' => 0,
        'per_unit_rate' => 0,
        'active' => true,
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.shipping_rates', 'shipping_rates');
    }

    // ─────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<ShippingZone, ShippingRate>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    // ─────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────

    /**
     * @param  Builder<ShippingRate>  $query
     * @return Builder<ShippingRate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<ShippingRate>  $query
     * @return Builder<ShippingRate>
     */
    public function scopeForCarrier(Builder $query, ?string $carrierCode): Builder
    {
        return $query->where(function ($q) use ($carrierCode): void {
            $q->whereNull('carrier_code')
                ->orWhere('carrier_code', $carrierCode);
        });
    }

    // ─────────────────────────────────────────────────────────────
    // CONDITION EVALUATION
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if this rate's conditions are met for the given context.
     *
     * @param  array<PackageData>  $packages
     */
    public function meetsConditions(array $packages, int $cartTotal = 0, int $itemCount = 0): bool
    {
        $conditions = $this->conditions;

        if ($conditions === null || $conditions === []) {
            return true;
        }

        $totalWeight = array_sum(array_map(fn (PackageData $p) => $p->weight, $packages));

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? null;
            $value = (int) ($condition['value'] ?? 0);

            $passes = match ($type) {
                'min_weight' => $totalWeight >= $value,
                'max_weight' => $totalWeight <= $value,
                'min_order_total' => $cartTotal >= $value,
                'max_order_total' => $cartTotal <= $value,
                'min_items' => $itemCount >= $value,
                'max_items' => $itemCount <= $value,
                default => true,
            };

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // RATE CALCULATION
    // ─────────────────────────────────────────────────────────────

    /**
     * Calculate rate for given packages.
     *
     * @param  array<PackageData>  $packages
     */
    public function calculateRate(array $packages, int $cartTotal = 0): int
    {
        // Check free shipping threshold
        if ($this->free_shipping_threshold !== null && $cartTotal >= $this->free_shipping_threshold) {
            return 0;
        }

        $totalWeight = array_sum(array_map(fn (PackageData $p) => $p->weight, $packages));
        $totalItems = array_sum(array_map(fn (PackageData $p) => $p->quantity, $packages));

        $rate = match ($this->calculation_type) {
            'flat' => $this->base_rate,
            'per_kg' => $this->calculatePerKgRate($totalWeight),
            'per_item' => $this->calculatePerItemRate($totalItems),
            'percentage' => $this->calculatePercentageRate($cartTotal),
            'table' => $this->calculateTableRate($totalWeight),
            default => $this->base_rate,
        };

        // Apply min/max constraints
        if ($this->min_charge !== null) {
            $rate = max($rate, $this->min_charge);
        }

        if ($this->max_charge !== null) {
            $rate = min($rate, $this->max_charge);
        }

        return $rate;
    }

    public function getDeliveryEstimate(): ?string
    {
        if ($this->estimated_days_min === null && $this->estimated_days_max === null) {
            return null;
        }

        if ($this->estimated_days_min === $this->estimated_days_max) {
            $days = $this->estimated_days_min;

            return $days === 1 ? '1 day' : "{$days} days";
        }

        return "{$this->estimated_days_min}-{$this->estimated_days_max} days";
    }

    public function getFormattedBaseRateAttribute(): string
    {
        return $this->formatMoney($this->base_rate);
    }

    protected function casts(): array
    {
        return [
            'base_rate' => 'integer',
            'per_unit_rate' => 'integer',
            'min_charge' => 'integer',
            'max_charge' => 'integer',
            'free_shipping_threshold' => 'integer',
            'rate_table' => 'array',
            'estimated_days_min' => 'integer',
            'estimated_days_max' => 'integer',
            'conditions' => 'array',
            'active' => 'boolean',
        ];
    }

    protected function calculatePerKgRate(int $weightGrams): int
    {
        $weightKg = ceil($weightGrams / 1000);

        return (int) ($this->base_rate + ($this->per_unit_rate * max(0, $weightKg - 1)));
    }

    protected function calculatePerItemRate(int $totalItems): int
    {
        return (int) ($this->base_rate + ($this->per_unit_rate * max(0, $totalItems - 1)));
    }

    protected function calculatePercentageRate(int $cartTotal): int
    {
        return (int) (($cartTotal * $this->per_unit_rate) / 10000); // per_unit_rate is in basis points
    }

    protected function calculateTableRate(int $weightGrams): int
    {
        if ($this->rate_table === null || empty($this->rate_table)) {
            return $this->base_rate;
        }

        foreach ($this->rate_table as $tier) {
            $min = $tier['min_weight'] ?? 0;
            $max = $tier['max_weight'] ?? PHP_INT_MAX;

            if ($weightGrams >= $min && $weightGrams <= $max) {
                return $tier['rate'] ?? $this->base_rate;
            }
        }

        // Return last tier rate for weights exceeding all tiers
        return $this->rate_table[count($this->rate_table) - 1]['rate'] ?? $this->base_rate;
    }
}
