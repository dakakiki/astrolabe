<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AstrologyMethodController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\ClientArchiveController;
use App\Http\Controllers\Api\V1\ClientBirthDetailsController;
use App\Http\Controllers\Api\V1\ClientChartController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientRelationshipController;
use App\Http\Controllers\Api\V1\ClientTimelineController;
use App\Http\Controllers\Api\V1\ConsultationChartController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\RelatedPersonChartController;
use App\Http\Controllers\Api\V1\RelatedPersonController;
use App\Http\Controllers\Api\V1\RelatedPersonConversionController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TaskController;
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
            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');
            Route::patch('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
            Route::put('/workspace/astrology-methods', [WorkspaceAstrologyMethodController::class, 'update'])
                ->name('workspace.astrology-methods.update');

            Route::apiResource('astrology-methods', AstrologyMethodController::class)->except('show');

            Route::apiResource('clients', ClientController::class)->except('destroy');
            Route::put('/clients/{client}/birth-details', [ClientBirthDetailsController::class, 'update'])
                ->name('clients.birth-details.update');
            Route::get('/clients/{client}/chart', [ClientChartController::class, 'show'])->name('clients.chart');
            Route::post('/clients/{client}/archive', [ClientArchiveController::class, 'store'])->name('clients.archive');
            Route::delete('/clients/{client}/archive', [ClientArchiveController::class, 'destroy'])->name('clients.restore');

            Route::get('/clients/{client}/timeline', [ClientTimelineController::class, 'index'])->name('clients.timeline');

            // Related people and links between clients (docs/spec/02, "Povezane osobe").
            Route::get('/clients/{client}/relationships', [ClientRelationshipController::class, 'index'])
                ->name('clients.relationships.index');
            Route::post('/clients/{client}/relationships', [ClientRelationshipController::class, 'store'])
                ->name('clients.relationships.store');
            Route::patch('/client-relationships/{relationship}', [ClientRelationshipController::class, 'update'])
                ->name('client-relationships.update');
            Route::delete('/client-relationships/{relationship}', [ClientRelationshipController::class, 'destroy'])
                ->name('client-relationships.destroy');
            Route::apiResource('related-people', RelatedPersonController::class)
                ->except('index')
                ->parameters(['related-people' => 'relatedPerson']);
            Route::get('/related-people/{relatedPerson}/chart', [RelatedPersonChartController::class, 'show'])
                ->name('related-people.chart');
            Route::post('/related-people/{relatedPerson}/convert', [RelatedPersonConversionController::class, 'store'])
                ->name('related-people.convert');

            Route::apiResource('services', ServiceController::class);

            // The calendar (docs/spec/10). No DELETE: appointments are cancelled, never removed.
            Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
            Route::post('/appointments', [AppointmentController::class, 'store'])
                ->middleware('idempotent')
                ->name('appointments.store');
            Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
            Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
            Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
                ->name('appointments.cancel');

            Route::apiResource('consultations', ConsultationController::class);
            Route::post('/consultations/{consultation}/chart', [ConsultationChartController::class, 'store'])
                ->name('consultations.chart.store');
            Route::delete('/consultations/{consultation}/chart', [ConsultationChartController::class, 'destroy'])
                ->name('consultations.chart.destroy');

            Route::apiResource('notes', NoteController::class);

            // Tasks and follow-ups (docs/spec/02, "Zadaci i follow-up").
            Route::post('/tasks', [TaskController::class, 'store'])->middleware('idempotent')->name('tasks.store');
            Route::apiResource('tasks', TaskController::class)->except('store');

            Route::get('/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
            Route::post('/attachments', [AttachmentController::class, 'store'])
                ->middleware('throttle:60,1')
                ->name('attachments.store');
            Route::patch('/attachments/{attachment}', [AttachmentController::class, 'update'])->name('attachments.update');
            Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
            Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
                ->name('attachments.download');

            Route::get('/tags', [TagController::class, 'index'])->name('tags.index');

            // Birth-place lookup in the local GeoNames copy.
            Route::get('/places', [PlaceController::class, 'index'])->name('places.index');
            Route::get('/places/nearest', [PlaceController::class, 'nearest'])->name('places.nearest');
        });
    });
});
