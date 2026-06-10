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

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (Schema::hasColumn($tableName, 'label_url')) {
                $table->dropColumn('label_url');
            }

            if (Schema::hasColumn($tableName, 'label_format')) {
                $table->dropColumn('label_format');
            }
        });
    }
};
