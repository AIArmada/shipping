<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.return_authorizations', 'return_authorizations');
        $itemsTable = config('shipping.database.tables.return_authorization_items', 'return_authorization_items');
        $jsonType = (string) config('shipping.database.json_column_type', commerce_json_column_type('shipping', 'json'));

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');

            $table->string('rma_number')->unique();
            $table->foreignUuid('original_shipment_id')->nullable();

            $table->string('order_reference')->nullable();
            $table->foreignUuid('customer_id')->nullable();

            $table->string('status', 50)->default('pending');
            $table->string('type', 50);
            $table->string('reason', 100);
            $table->text('reason_details')->nullable();

            $table->foreignUuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->{$jsonType}('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'owner_type', 'status'], $tableName . '_owner_status');
        });

        Schema::create($itemsTable, function (Blueprint $table) use ($itemsTable, $jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('return_authorization_id');

            $table->nullableUuidMorphs('original_item');

            $table->string('sku')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity_requested')->default(1);
            $table->unsignedInteger('quantity_approved')->default(0);
            $table->unsignedInteger('quantity_received')->default(0);

            $table->string('reason', 100)->nullable();
            $table->string('condition', 50)->nullable();

            $table->{$jsonType}('metadata')->nullable();
            $table->timestamps();

            $table->index('return_authorization_id', $itemsTable . '_ra_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.return_authorization_items', 'return_authorization_items'));
        Schema::dropIfExists(config('shipping.database.tables.return_authorizations', 'return_authorizations'));
    }
};
