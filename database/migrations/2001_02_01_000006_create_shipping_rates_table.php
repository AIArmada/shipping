<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipping_rates', 'shipping_rates');
        $jsonType = (string) config('shipping.database.json_column_type', commerce_json_column_type('shipping', 'json'));

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id');

            $table->string('carrier_code', 50)->nullable();
            $table->string('method_code', 50);
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('calculation_type', 20);
            $table->unsignedInteger('base_rate')->default(0);
            $table->unsignedInteger('per_unit_rate')->default(0);
            $table->unsignedInteger('min_charge')->nullable();
            $table->unsignedInteger('max_charge')->nullable();
            $table->unsignedInteger('free_shipping_threshold')->nullable();

            $table->{$jsonType}('rate_table')->nullable();

            $table->unsignedTinyInteger('estimated_days_min')->nullable();
            $table->unsignedTinyInteger('estimated_days_max')->nullable();

            $table->{$jsonType}('conditions')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['zone_id', 'carrier_code', 'active'], $tableName . '_zone_carrier_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipping_rates', 'shipping_rates'));
    }
};
