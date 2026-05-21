<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipment_events', 'shipment_events');
        $jsonType = (string) config('shipping.database.json_column_type', commerce_json_column_type('shipping', 'json'));

        Schema::create($tableName, function (Blueprint $table) use ($tableName, $jsonType): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id');

            $table->string('carrier_event_code', 50)->nullable();
            $table->string('normalized_status', 50)->index();
            $table->text('description')->nullable();

            $table->string('location')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('postcode', 20)->nullable();

            $table->timestamp('occurred_at')->index();
            $table->{$jsonType}('raw_data')->nullable();

            $table->timestamps();

            $table->unique(
                ['shipment_id', 'carrier_event_code', 'occurred_at'],
                $tableName . '_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipment_events', 'shipment_events'));
    }
};
