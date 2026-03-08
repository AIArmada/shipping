<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Actions;

use AIArmada\Shipping\Models\Shipment;
use AIArmada\Shipping\States\Draft;
use AIArmada\Shipping\States\ShipmentStatus as ShipmentStatusState;
use AIArmada\Shipping\Support\ShippingOwnerScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create a new shipment.
 */
final class CreateShipment
{
    use AsAction;

    /**
     * Create a new shipment.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Shipment
    {
        Validator::make($data, [
            'carrier_code' => ['required_without:carrier', 'string'],
            'carrier' => ['required_without:carrier_code', 'string'],
            'origin_address' => ['required', 'array'],
            'destination_address' => ['required', 'array'],
        ])->validate();

        return DB::transaction(function () use ($data): Shipment {
            $owner = ShippingOwnerScope::resolveOwner();

            if (ShippingOwnerScope::isEnabled() && $owner === null) {
                throw new AuthorizationException('Owner context is required when shipping owner scoping is enabled.');
            }

            if (array_key_exists('owner_id', $data) || array_key_exists('owner_type', $data)) {
                $providedOwnerId = $data['owner_id'] ?? null;
                $providedOwnerType = $data['owner_type'] ?? null;

                if ($owner === null) {
                    if ($providedOwnerId !== null || $providedOwnerType !== null) {
                        throw new AuthorizationException('Cannot assign an owner without an owner context.');
                    }
                } elseif ($providedOwnerId !== $owner->getKey() || $providedOwnerType !== $owner->getMorphClass()) {
                    throw new AuthorizationException('Cannot assign an owner outside the current owner context.');
                }
            }

            $status = $data['status'] ?? Draft::class;

            if ($status instanceof ShipmentStatusState) {
                $status = $status->getValue();
            }

            if (! is_string($status)) {
                $status = Draft::class;
            }

            $status = ShipmentStatusState::normalize($status);

            $statusClass = ShipmentStatusState::resolveStateClass($status) ?? Draft::class;

            if (! is_string($statusClass) || ! is_subclass_of($statusClass, ShipmentStatusState::class)) {
                $statusClass = Draft::class;
            }

            $shipment = Shipment::create([
                'reference' => $data['reference'] ?? $this->generateReference(),
                'status' => $statusClass,
                'carrier_code' => $data['carrier_code'] ?? $data['carrier'] ?? '',
                'service_code' => $data['service_code'] ?? $data['service_type'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'origin_address' => $data['origin_address'],
                'destination_address' => $data['destination_address'],
                'total_weight' => $data['weight'] ?? $data['total_weight'] ?? 0,
                'declared_value' => $data['declared_value'] ?? 0,
                'shipping_cost' => $data['rate_minor'] ?? $data['shipping_cost'] ?? 0,
                'currency' => $data['currency'] ?? config('shipping.defaults.currency', 'MYR'),
                'estimated_delivery_at' => $data['estimated_delivery_at'] ?? null,
                'shipped_at' => $data['shipped_at'] ?? null,
                'delivered_at' => $data['delivered_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'shippable_type' => $data['shippable_type'] ?? null,
                'shippable_id' => $data['shippable_id'] ?? null,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getKey(),
            ]);

            return $shipment;
        });
    }

    private function generateReference(): string
    {
        $prefix = config('shipping.defaults.reference_prefix', 'SHP-');

        return $prefix . Str::upper(Str::random(10));
    }
}
