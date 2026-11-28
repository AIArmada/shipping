<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Api;

interface ManageReturnAuthorization
{
    /** @param array<string, mixed> $data */
    public function create(array $data): string;

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void;

    public function delete(string $id): bool;

    /** @param array<string, mixed> $data */
    public function createItem(string $id, array $data): string;

    /** @param array<string, mixed> $data */
    public function updateItem(string $id, string $itemId, array $data): void;

    public function deleteItem(string $id, string $itemId): bool;
}
