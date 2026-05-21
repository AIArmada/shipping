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

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->index('method_code', $tableName . '_method_code');
        });
    }
};
