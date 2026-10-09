<?php

use App\Http\Controllers\Portal\AppointmentController;
use App\Http\Controllers\Portal\DeviceController;
use App\Http\Controllers\Portal\HomeController;
use App\Http\Controllers\Portal\InvitationController;
use App\Http\Controllers\Portal\LogoController;
use App\Http\Controllers\Portal\PageController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\SessionController;
use App\Http\Controllers\Portal\SharedController;
use App\Http\Controllers\Portal\SignInController;
use Illuminate\Support\Facades\Route;

/*
| The client portal (docs/spec/12), on the portal host only, in the `portal`
| middleware group: its own session cookie and table and CSRF cookie
| (PortalServiceProvider). Tokens from emails travel in request bodies, never
| in URLs. `portal.auth` = signed in; `portal.access` = and a practice is open,
| which sets the tenant scope to it.
*/

Route::prefix('api/portal/v1')->name('portal.')->group(function () {
    // A practice's logo for pages shown before any practice is open: a signed link, no session.
    Route::get('/practices/{workspace}/logo', LogoController::class)
        ->whereNumber('workspace')
        ->middleware(['signed:relative', 'throttle:120,1'])
        ->name('logo');

    Route::middleware('throttle:portal')->group(function () {
        Route::get('/session', [SessionController::class, 'show'])->name('session');

        Route::post('/invitations/preview', [InvitationController::class, 'show'])
            ->middleware('throttle:30,60')
            ->name('invitations.preview');
        Route::post('/invitations/accept', [InvitationController::class, 'accept'])
            ->middleware('throttle:portal-invitation')
            ->name('invitations.accept');

        // Limited per address and per IP inside (the address is in the body).
        Route::post('/sign-in', [SignInController::class, 'request'])->name('sign-in');
        Route::post('/sign-in/link', [SignInController::class, 'link'])->middleware('throttle:portal-code')->name('sign-in.link');
        Route::post('/sign-in/code', [SignInController::class, 'code'])->middleware('throttle:portal-code')->name('sign-in.code');

        Route::middleware('portal.auth')->group(function () {
            Route::post('/sign-out', [SessionController::class, 'destroy'])->name('sign-out');
            Route::put('/practice', [SessionController::class, 'practice'])->name('practice');

            // Portal → Security: where the person is signed in, and signing out everywhere.
            Route::get('/sessions', [DeviceController::class, 'index'])->name('sessions.index');
            Route::delete('/sessions', [DeviceController::class, 'destroy'])->name('sessions.destroy');

            Route::middleware('portal.access')->group(function () {
                Route::get('/home', HomeController::class)->name('home');
                Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
                Route::get('/shared', [SharedController::class, 'index'])->name('shared.index');
                Route::get('/files/{attachment}/download', [SharedController::class, 'download'])
                    ->whereNumber('attachment')
                    ->name('files.download');
                Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
                Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            });
        });
    });
});

// The portal SPA owns every other path on the portal host. The application's API and
// Sanctum paths are left to answer 404 there (RejectOnPortalHost).
Route::get('/{any?}', PageController::class)
    ->where('any', '^(?!api/|sanctum/).*')
    ->name('portal.spa');
