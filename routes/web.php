<?php

use App\Http\Controllers\Api\V1\WorkspaceExportController;
use App\Http\Controllers\Auth\OtherSessionsController;
use App\Http\Controllers\Auth\RegistrationController;
use Illuminate\Support\Facades\Route;

// Session management sits next to Fortify's auth endpoints (config/fortify.php):
// same prefix and the same session-based "web" middleware, since it works on sessions.
Route::prefix('api/v1/auth')->middleware(['throttle:auth', 'auth'])->group(function () {
    Route::get('/other-sessions', [OtherSessionsController::class, 'show'])->name('other-sessions.show');
    Route::delete('/other-sessions', [OtherSessionsController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('other-sessions.destroy');
});

// Before registering: open or by invitation (closed beta), and the invitation's state.
Route::get('/api/v1/auth/registration', [RegistrationController::class, 'show'])
    ->middleware('throttle:auth')
    ->name('registration.show');

// Named so framework redirects (e.g. an expired session) have somewhere to go.
Route::view('/login', 'app')->name('login');

// The link in the "export ready" email (Phase 8b): signed for a day, and still only
// for the practice's owner, signed in. A guest is sent to sign in and comes back here.
Route::get('/exports/{export}/download', [WorkspaceExportController::class, 'download'])
    ->middleware(['auth', 'verified', 'workspace', 'signed', 'throttle:30,1'])
    ->name('practice-exports.link');

// The Vue SPA owns every path except the API, Sanctum and the health check,
// which are registered separately and take precedence.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|sanctum/|up$).*')
    ->name('spa');
