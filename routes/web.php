<?php

declare(strict_types=1);

use AIArmada\Shipping\Http\Controllers\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('shipping')
    ->name('shipping.')
    ->group(function (): void {
        Route::get('/labels/{trackingNumber}', [LabelController::class, 'show'])
            ->name('labels.show')
            ->middleware('signed');
    });
