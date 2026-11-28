<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Api;

interface PackageReturnWorkflow
{
    /** @return array<string, string> */
    public function shippedUnits(string $shipmentId): array;

    public function request(string $shipmentId, string $orderItemId, int $ordinal, string $reason): string;

    /** @param array<string, array{quantity: int, condition: string, restock_quantity?: int}> $inspection */
    public function receive(string $authorizationId, array $inspection, ?string $locationId = null): string;

    public function complete(string $authorizationId, ?string $transactionId = null): string;
}
