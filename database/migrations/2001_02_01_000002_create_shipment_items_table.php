<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipment_items', 'shipment_items');
        $shipmentsTable = config('shipping.database.tables.shipments', 'shipments');
        $jsonType = (string) config('shipping.database.json_column_type', 'json');

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id');

            $table->nullableUuidMorphs('shippable_item');

            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('weight')->default(0);
            $table->unsignedInteger('declared_value')->default(0);

            $table->string('hs_code')->nullable();
            $table->string('origin_country', 3)->nullable();

            $table->{$jsonType}('metadata')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'sku'], $tableName . '_shipment_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipment_items', 'shipment_items'));
    }
};
