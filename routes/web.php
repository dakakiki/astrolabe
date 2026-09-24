<?php

use App\Http\Controllers\Auth\OtherSessionsController;
use Illuminate\Support\Facades\Route;

// Session management sits next to Fortify's auth endpoints (config/fortify.php):
// same prefix and the same session-based "web" middleware, since it works on sessions.
Route::prefix('api/v1/auth')->middleware(['throttle:auth', 'auth'])->group(function () {
    Route::get('/other-sessions', [OtherSessionsController::class, 'show'])->name('other-sessions.show');
    Route::delete('/other-sessions', [OtherSessionsController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('other-sessions.destroy');
});

// Named so framework redirects (e.g. an expired session) have somewhere to go.
Route::view('/login', 'app')->name('login');

// The Vue SPA owns every path except the API, Sanctum and the health check,
// which are registered separately and take precedence.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|sanctum/|up$).*')
    ->name('spa');
