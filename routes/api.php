<?php

use App\Http\Controllers\Api\V1\AstrologyMethodController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\WorkspaceAstrologyMethodController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| All endpoints are versioned under /api/v1 — see docs/api-conventions.md.
| Authentication endpoints (login, register, password reset, email
| verification, profile and password updates) are registered by Fortify
| under /api/v1/auth — see config/fortify.php.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/status', StatusController::class)->name('status');

    Route::middleware(['auth:sanctum', 'workspace'])->group(function () {
        // Reachable before the email address is verified.
        Route::get('/me', MeController::class)->name('me');

        Route::middleware('verified')->group(function () {
            Route::get('/reference-data', ReferenceDataController::class)->name('reference-data');

            Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');
            Route::patch('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
            Route::put('/workspace/astrology-methods', [WorkspaceAstrologyMethodController::class, 'update'])
                ->name('workspace.astrology-methods.update');

            Route::apiResource('astrology-methods', AstrologyMethodController::class)->except('show');
        });
    });
});
