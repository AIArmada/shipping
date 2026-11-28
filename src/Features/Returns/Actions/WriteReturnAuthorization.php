<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Shipping\Features\Returns\Api\ManageReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorizationItem;
use AIArmada\Shipping\Features\Returns\States\RmaDraft;
use AIArmada\Shipping\Features\Returns\States\RmaPending;
use DomainException;
use Illuminate\Support\Facades\DB;

final class WriteReturnAuthorization implements ManageReturnAuthorization
{
    private const array FIELDS = ['order_reference', 'type', 'reason', 'reason_details', 'expires_at'];

    private const array ITEM_FIELDS = ['name', 'sku', 'quantity_requested', 'quantity_received', 'condition'];

    public function create(array $data): string
    {
        $record = new ReturnAuthorization(array_intersect_key($data, array_flip(self::FIELDS)));

        if ((bool) config('shipping.features.owner.enabled', false)) {
            $owner = OwnerContext::resolve();
            OwnerContext::assertResolvedOrExplicitGlobal($owner, 'Return creation requires an owner context.');
            $record->forceFill(['owner_id' => $owner?->getKey(), 'owner_type' => $owner?->getMorphClass()]);
        }

        $record->setAttribute('status', RmaPending::class);
        $record->save();

        return (string) $record->getKey();
    }

    public function update(string $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $record = $this->lock($id);
            $this->assertEditable($record);
            $record->update(array_intersect_key($data, array_flip(self::FIELDS)));
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $record = $this->lock($id);
            $this->assertEditable($record);

            return (bool) $record->delete();
        });
    }

    public function createItem(string $id, array $data): string
    {
        return DB::transaction(function () use ($id, $data): string {
            $record = $this->lock($id);
            $this->assertEditable($record);
            $item = new ReturnAuthorizationItem($this->itemData($data));
            $this->assertQuantities($item);
            $record->items()->save($item);

            return (string) $item->getKey();
        });
    }

    public function updateItem(string $id, string $itemId, array $data): void
    {
        DB::transaction(function () use ($id, $itemId, $data): void {
            $record = $this->lock($id);
            $this->assertEditable($record);
            $item = $record->items()->lockForUpdate()->findOrFail($itemId);
            $item->fill($this->itemData($data));
            $this->assertQuantities($item);
            $item->save();
        });
    }

    public function deleteItem(string $id, string $itemId): bool
    {
        return DB::transaction(function () use ($id, $itemId): bool {
            $record = $this->lock($id);
            $this->assertEditable($record);

            return (bool) $record->items()->lockForUpdate()->findOrFail($itemId)->delete();
        });
    }

    private function lock(string $id): ReturnAuthorization
    {
        if ((bool) config('shipping.features.owner.enabled', false)) {
            OwnerWriteGuard::findOrFailForOwner(ReturnAuthorization::class, $id);
        }

        return ReturnAuthorization::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertEditable(ReturnAuthorization $record): void
    {
        if ($record->isPackageReturn() || (! $record->isPending() && ! $record->status instanceof RmaDraft)) {
            throw new DomainException('Only ordinary draft or pending returns can be edited or deleted.');
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function itemData(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::ITEM_FIELDS));
        unset($data['quantity_received'], $data['condition']);

        return $data;
    }

    private function assertQuantities(ReturnAuthorizationItem $item): void
    {
        if ($item->quantity_requested < 1 || $item->quantity_requested < $item->quantity_approved || $item->quantity_requested < $item->quantity_received) {
            throw new DomainException('Requested quantity must be positive and cover approved and received quantities.');
        }
    }
}
