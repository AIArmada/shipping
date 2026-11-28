<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Persistence;

use AIArmada\Shipping\Features\Returns\Models\ReturnAuthorization;
use Illuminate\Support\Facades\DB;

final readonly class DeleteReturnItems
{
    public function handle(ReturnAuthorization $return): void
    {
        DB::transaction(fn () => $return->items()->delete());
    }
}
