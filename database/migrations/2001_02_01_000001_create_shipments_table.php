<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipments', 'shipments');
        $jsonType = (string) config('shipping.database.json_column_type', commerce_json_column_type('shipping', 'json'));

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->ulid('ulid')->unique();

            $table->nullableUuidMorphs('owner');
            $table->nullableUuidMorphs('shippable');

            $table->string('reference')->index();
            $table->string('carrier_code', 50)->index();
            $table->string('service_code', 50)->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->string('carrier_reference')->nullable();

            $table->string('status', 50)->default('draft')->index();

            $table->{$jsonType}('origin_address');
            $table->{$jsonType}('destination_address');

            $table->unsignedInteger('package_count')->default(1);
            $table->unsignedInteger('total_weight')->default(0);
            $table->unsignedInteger('declared_value')->default(0);
            $table->string('currency', 3)->default(config('shipping.defaults.currency', 'MYR'));

            $table->unsignedInteger('shipping_cost')->default(0);
            $table->unsignedInteger('insurance_cost')->default(0);
            $table->unsignedInteger('cod_amount')->nullable();

            $table->string('label_url')->nullable();
            $table->string('label_format', 10)->nullable();

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_tracking_sync')->nullable();

            $table->{$jsonType}('metadata')->nullable();

            $table->timestamps();

            $table->index(['owner_id', 'owner_type', 'status'], $tableName . '_owner_status');
            $table->index(['carrier_code', 'status', 'created_at'], $tableName . '_carrier_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipments', 'shipments'));
    }
};
