<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipment_operations', 'shipment_operations');

        Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('operation_type'); // create, cancel
            $table->string('status')->default('pending')->index();
            $table->string('outcome_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('carrier_reference')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('error_message')->nullable();
            $table->json('carrier_result')->nullable();
            $table->timestampTz('operation_started_at')->nullable();
            $table->timestampTz('operation_completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'operation_type'], $tableName . '_status_type');
        });
    }
};
