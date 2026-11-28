<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerScopeOverride;
use AIArmada\Shipping\Features\Shipments\Api\ShipmentStatistics;
use AIArmada\Shipping\Features\Shipments\Models\Shipment;
use AIArmada\Shipping\Features\Shipments\States\Delivered;
use AIArmada\Shipping\Features\Shipments\States\DeliveryFailed;
use AIArmada\Shipping\Features\Shipments\States\ExceptionStatus;
use AIArmada\Shipping\Features\Shipments\States\InTransit;
use AIArmada\Shipping\Features\Shipments\States\OutForDelivery;
use AIArmada\Shipping\Features\Shipments\States\Pending;
use AIArmada\Shipping\Features\Shipments\States\ShipmentStatus;
use AIArmada\Shipping\Features\Shipments\States\Shipped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReadShipmentStatistics implements ShipmentStatistics
{
    /**
     * @return array{total: int, pending: int, inTransit: int, deliveredToday: int, exceptions: int}
     */
    public function getAllStats(): array
    {
        $pendingStatus = ShipmentStatus::normalize(Pending::class);
        $inTransitStatuses = [
            ShipmentStatus::normalize(Shipped::class),
            ShipmentStatus::normalize(InTransit::class),
            ShipmentStatus::normalize(OutForDelivery::class),
        ];
        $exceptionStatuses = [
            ShipmentStatus::normalize(ExceptionStatus::class),
            ShipmentStatus::normalize(DeliveryFailed::class),
        ];

        $counts = $this->shipmentQuery()
            ->whereIn('status', [$pendingStatus, ...$inTransitStatuses, ...$exceptionStatuses])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        $pending = (int) $counts->get($pendingStatus, 0);
        $inTransit = array_sum(array_map(static fn (string $status): int => (int) $counts->get($status, 0), $inTransitStatuses));
        $exceptions = array_sum(array_map(static fn (string $status): int => (int) $counts->get($status, 0), $exceptionStatuses));
        $deliveredToday = $this->shipmentQuery()
            ->where('status', ShipmentStatus::normalize(Delivered::class))
            ->whereDate('delivered_at', CarbonImmutable::today())
            ->count();

        return [
            'total' => $pending + $inTransit + $deliveredToday + $exceptions,
            'pending' => $pending,
            'inTransit' => $inTransit,
            'deliveredToday' => $deliveredToday,
            'exceptions' => $exceptions,
        ];
    }

    /** @return array{pending: int, exceptions: int} */
    public function pendingActions(): array
    {
        $pending = ShipmentStatus::normalize(Pending::class);
        $exceptions = [ShipmentStatus::normalize(ExceptionStatus::class), ShipmentStatus::normalize(DeliveryFailed::class)];
        $shipments = $this->shipmentQuery()
            ->whereIn('status', [$pending, ...$exceptions])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) $shipments->get($pending, 0),
            'exceptions' => array_sum(array_map(static fn (string $status): int => (int) $shipments->get($status, 0), $exceptions)),
        ];
    }

    /** @return list<array{carrier: string, delivered: int, inTransit: int, exceptions: int}> */
    public function carrierPerformance(): array
    {
        $startDate = CarbonImmutable::now()->subDays(30);

        $query = $this->shipmentQuery();

        $delivered = ShipmentStatus::normalize(Delivered::class);
        $inTransitStatuses = [
            ShipmentStatus::normalize(Shipped::class),
            ShipmentStatus::normalize(InTransit::class),
            ShipmentStatus::normalize(OutForDelivery::class),
        ];
        $exceptionStatuses = [
            ShipmentStatus::normalize(ExceptionStatus::class),
            ShipmentStatus::normalize(DeliveryFailed::class),
        ];

        $inTransitPlaceholders = implode(', ', array_fill(0, count($inTransitStatuses), '?'));
        $exceptionPlaceholders = implode(', ', array_fill(0, count($exceptionStatuses), '?'));

        $rows = $query
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('carrier_code')
            ->select('carrier_code')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS delivered_count', [$delivered])
            ->selectRaw(
                'SUM(CASE WHEN status IN (' . $inTransitPlaceholders . ') THEN 1 ELSE 0 END) AS in_transit_count',
                $inTransitStatuses
            )
            ->selectRaw(
                'SUM(CASE WHEN status IN (' . $exceptionPlaceholders . ') THEN 1 ELSE 0 END) AS exceptions_count',
                $exceptionStatuses
            )
            ->groupBy('carrier_code')
            ->orderBy('carrier_code')
            ->toBase()
            ->get();

        return $rows->map(static fn (object $row): array => [
            'carrier' => (string) $row->carrier_code,
            'delivered' => (int) $row->delivered_count,
            'inTransit' => (int) $row->in_transit_count,
            'exceptions' => (int) $row->exceptions_count,
        ])->values()->all();
    }

    /**
     * @return Builder<Shipment>
     */
    private function shipmentQuery(): Builder
    {
        $query = Shipment::query()->withoutGlobalScope(OwnerScope::class);

        if ((bool) config('shipping.features.owner.enabled', false)) {
            $owner = OwnerContext::resolve();

            if ($owner === null && ! OwnerContext::isExplicitGlobal()) {
                return $query->whereRaw('0 = 1');
            }

            $query->forOwner($owner, includeGlobal: ! OwnerScopeOverride::suppressIncludeGlobal() && (bool) config('shipping.features.owner.include_global', false));
        }

        return $query;
    }
}
