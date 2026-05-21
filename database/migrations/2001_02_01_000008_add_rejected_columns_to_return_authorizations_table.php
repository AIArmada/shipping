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

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreignUuid('rejected_by')->nullable()->after('approved_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });
    }
};
