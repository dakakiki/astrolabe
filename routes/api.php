<?php

use App\Http\Controllers\Api\V1\AstrologyMethodController;
use App\Http\Controllers\Api\V1\ClientArchiveController;
use App\Http\Controllers\Api\V1\ClientBirthDetailsController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\TagController;
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

            Route::apiResource('clients', ClientController::class)->except('destroy');
            Route::put('/clients/{client}/birth-details', [ClientBirthDetailsController::class, 'update'])
                ->name('clients.birth-details.update');
            Route::post('/clients/{client}/archive', [ClientArchiveController::class, 'store'])->name('clients.archive');
            Route::delete('/clients/{client}/archive', [ClientArchiveController::class, 'destroy'])->name('clients.restore');

            Route::get('/tags', [TagController::class, 'index'])->name('tags.index');

            // Birth-place lookup in the local GeoNames copy.
            Route::get('/places', [PlaceController::class, 'index'])->name('places.index');
            Route::get('/places/nearest', [PlaceController::class, 'nearest'])->name('places.nearest');
        });
    });
});
