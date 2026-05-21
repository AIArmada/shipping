<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipping_zones', 'shipping_zones');
        $jsonType = (string) config('shipping.database.json_column_type', commerce_json_column_type('shipping', 'json'));

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');

            $table->string('name');
            $table->string('code', 50);
            $table->string('type', 20);

            $table->{$jsonType}('countries')->nullable();
            $table->{$jsonType}('states')->nullable();
            $table->{$jsonType}('postcode_ranges')->nullable();

            $table->decimal('center_lat', 10, 8)->nullable();
            $table->decimal('center_lng', 11, 8)->nullable();
            $table->unsignedInteger('radius_km')->nullable();

            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'code'], $tableName . '_owner_code_unique');
            $table->index(['owner_id', 'owner_type', 'active'], $tableName . '_owner_active');
            $table->index('priority', $tableName . '_priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipping_zones', 'shipping_zones'));
    }
};
