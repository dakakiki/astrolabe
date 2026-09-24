<?php

use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| All endpoints are versioned under /api/v1 — see docs/api-conventions.md.
*/

Route::prefix('v1')->group(function () {
    Route::get('/status', StatusController::class)->name('api.v1.status');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', fn (Request $request) => $request->user())->name('api.v1.user');
    });
});
