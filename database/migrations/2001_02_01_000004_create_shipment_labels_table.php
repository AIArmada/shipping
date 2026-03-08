<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('shipping.database.tables.shipment_labels', 'shipment_labels');

        Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shipment_id');

            $table->string('format', 10);
            $table->string('size', 10)->nullable();
            $table->string('url')->nullable();
            $table->longText('content')->nullable();

            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['shipment_id', 'format'], $tableName . '_shipment_format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shipping.database.tables.shipment_labels', 'shipment_labels'));
    }
};
