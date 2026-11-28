<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Api;

interface ManageRateConfiguration
{
    /** @param array<string, mixed> $data */
    public function createZone(array $data): string;

    /** @param array<string, mixed> $data */
    public function updateZone(string $zoneId, array $data): void;

    /** @param array<string, mixed> $data */
    public function createRate(string $zoneId, array $data): string;

    /** @param array<string, mixed> $data */
    public function updateRate(string $rateId, array $data): void;

    public function deleteRate(string $rateId): bool;

    public function deleteZone(string $zoneId): bool;
}
