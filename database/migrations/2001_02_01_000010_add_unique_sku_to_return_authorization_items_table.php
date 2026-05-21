<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $itemsTable = config('shipping.database.tables.return_authorization_items', 'return_authorization_items');

        Schema::table($itemsTable, function (Blueprint $table) use ($itemsTable): void {
            // Prevents duplicate SKU rows per RMA when sku is provided.
            // MySQL allows multiple NULLs in a unique index, so this only
            // enforces uniqueness for non-null SKUs (which is the desired behaviour).
            $table->unique(['return_authorization_id', 'sku'], $itemsTable . '_rma_sku_unique');
        });
    }
};
