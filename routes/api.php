<?php

use App\Http\Controllers\Api\V1\Admin\AdminAccountHelpController;
use App\Http\Controllers\Api\V1\Admin\AdminAstrologerController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminFeedbackController;
use App\Http\Controllers\Api\V1\Admin\AdminInvitationController;
use App\Http\Controllers\Api\V1\Admin\AdminSystemController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AstrologyMethodController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\ClientArchiveController;
use App\Http\Controllers\Api\V1\ClientBirthDetailsController;
use App\Http\Controllers\Api\V1\ClientChartController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientRelationshipController;
use App\Http\Controllers\Api\V1\ClientSynastryController;
use App\Http\Controllers\Api\V1\ClientTimelineController;
use App\Http\Controllers\Api\V1\ClientTransitController;
use App\Http\Controllers\Api\V1\ConsultationChartController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\NotificationPreferencesController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\RelatedPersonChartController;
use App\Http\Controllers\Api\V1\RelatedPersonController;
use App\Http\Controllers\Api\V1\RelatedPersonConversionController;
use App\Http\Controllers\Api\V1\RelatedPersonTransitController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SkyController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WorkspaceAstrologyMethodController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceDeletionController;
use App\Http\Controllers\Api\V1\WorkspaceExportController;
use Illuminate\Support\Facades\Route;

/*
| All endpoints are versioned under /api/v1 — see docs/api-conventions.md.
| Authentication endpoints (login, register, password reset, email
| verification, profile and password updates) are registered by Fortify
| under /api/v1/auth — see config/fortify.php.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/status', StatusController::class)->name('status');
    // For an uptime monitor: 200 or 503, one yes/no per part (Phase 8a).
    Route::get('/health', HealthController::class)->middleware('throttle:30,1')->name('health');

    // Reachable before the email address is verified, and by the admin, who has no practice.
    Route::get('/me', MeController::class)->middleware(['auth:sanctum', 'workspace:optional'])->name('me');

    // The operator's admin (Phase 8c): no practice, two-factor sign-in, everything audited.
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'verified', 'admin'])->group(function () {
        Route::get('/astrologers', [AdminAstrologerController::class, 'index'])->name('astrologers.index');
        Route::get('/astrologers/{user}', [AdminAstrologerController::class, 'show'])->name('astrologers.show');

        // Account help: each asks for the password again and a reason, and tells the astrologer.
        Route::middleware(['password.confirm:,'.config('astrolabe.admin.confirm_seconds'), 'throttle:20,1'])->group(function () {
            Route::post('/astrologers/{user}/two-factor-reset', [AdminAccountHelpController::class, 'resetTwoFactor'])
                ->name('astrologers.two-factor-reset');
            Route::post('/astrologers/{user}/verification', [AdminAccountHelpController::class, 'resendVerification'])
                ->name('astrologers.verification');
            Route::post('/astrologers/{user}/suspension', [AdminAccountHelpController::class, 'suspend'])
                ->name('astrologers.suspend');
            Route::delete('/astrologers/{user}/suspension', [AdminAccountHelpController::class, 'restore'])
                ->name('astrologers.restore');
        });

        Route::get('/audit-logs', AdminAuditLogController::class)->name('audit-logs.index');

        Route::get('/invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
        Route::post('/invitations', [AdminInvitationController::class, 'store'])
            ->middleware('throttle:30,60')
            ->name('invitations.store');
        Route::delete('/invitations/{invitation}', [AdminInvitationController::class, 'destroy'])->name('invitations.destroy');

        Route::get('/system', [AdminSystemController::class, 'show'])->name('system.show');
        Route::post('/failed-jobs/{uuid}/retry', [AdminSystemController::class, 'retry'])->name('failed-jobs.retry');
        Route::delete('/failed-jobs/{uuid}', [AdminSystemController::class, 'forget'])->name('failed-jobs.forget');

        Route::get('/feedback', [AdminFeedbackController::class, 'index'])->name('feedback.index');
        Route::patch('/feedback/{feedback}', [AdminFeedbackController::class, 'update'])->name('feedback.update');
    });

    Route::middleware(['auth:sanctum', 'workspace'])->group(function () {
        Route::middleware('verified')->group(function () {
            // Open while the practice is scheduled for deletion; everything in the
            // "practice.active" group below is closed then (EnsurePracticeIsActive).
            Route::get('/reference-data', ReferenceDataController::class)->name('reference-data');
            Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');

            // Settings → Your data (Phase 8b): the practice export, and deleting the practice.
            Route::get('/workspace/exports', [WorkspaceExportController::class, 'index'])->name('workspace.exports.index');
            Route::post('/workspace/exports', [WorkspaceExportController::class, 'store'])
                ->middleware('throttle:6,60')
                ->name('workspace.exports.store');
            Route::get('/workspace/exports/{export}/download', [WorkspaceExportController::class, 'download'])
                ->name('workspace.exports.download');
            Route::post('/workspace/deletion', [WorkspaceDeletionController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('workspace.deletion.store');
            Route::delete('/workspace/deletion', [WorkspaceDeletionController::class, 'destroy'])
                ->name('workspace.deletion.destroy');

            Route::middleware('practice.active')->group(function () {
                // "throttle:engine" marks every endpoint that may start the ephemeris engine.
                Route::get('/dashboard', DashboardController::class)->middleware('throttle:engine')->name('dashboard');

                // The "Feedback" button: to the operator's admin (Phase 8c).
                Route::post('/feedback', FeedbackController::class)->middleware('throttle:10,60')->name('feedback.store');

                Route::patch('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
                Route::put('/workspace/astrology-methods', [WorkspaceAstrologyMethodController::class, 'update'])
                    ->name('workspace.astrology-methods.update');

                // The person's own email notifications (Settings → Notifications).
                Route::put('/notification-preferences', [NotificationPreferencesController::class, 'update'])
                    ->name('notification-preferences.update');
                Route::post('/notification-preferences/test', [NotificationPreferencesController::class, 'test'])
                    ->middleware('throttle:3,10')
                    ->name('notification-preferences.test');

                Route::apiResource('astrology-methods', AstrologyMethodController::class)->except('show');

                Route::apiResource('clients', ClientController::class);
                Route::put('/clients/{client}/birth-details', [ClientBirthDetailsController::class, 'update'])
                    ->name('clients.birth-details.update');
                Route::middleware('throttle:engine')->group(function () {
                    Route::get('/clients/{client}/chart', [ClientChartController::class, 'show'])->name('clients.chart');
                    Route::get('/clients/{client}/transits', [ClientTransitController::class, 'show'])->name('clients.transits');
                    // Another person's chart laid over the client's, with the composite (Phase 7e).
                    Route::get('/clients/{client}/synastry', [ClientSynastryController::class, 'show'])->name('clients.synastry');
                });
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
                Route::middleware('throttle:engine')->group(function () {
                    Route::get('/related-people/{relatedPerson}/chart', [RelatedPersonChartController::class, 'show'])
                        ->name('related-people.chart');
                    Route::get('/related-people/{relatedPerson}/transits', [RelatedPersonTransitController::class, 'show'])
                        ->name('related-people.transits');
                });
                Route::post('/related-people/{relatedPerson}/convert', [RelatedPersonConversionController::class, 'store'])
                    ->name('related-people.convert');

                // The sky itself, nobody's chart (Phase 7d).
                Route::get('/sky', SkyController::class)->middleware('throttle:engine')->name('sky');

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
                    ->middleware('throttle:engine')
                    ->name('consultations.chart.store');
                Route::delete('/consultations/{consultation}/chart', [ConsultationChartController::class, 'destroy'])
                    ->name('consultations.chart.destroy');

                Route::apiResource('notes', NoteController::class);

                // Tasks and follow-ups (docs/spec/02, "Zadaci i follow-up").
                Route::post('/tasks', [TaskController::class, 'store'])->middleware('idempotent')->name('tasks.store');
                Route::apiResource('tasks', TaskController::class)->except('store');

                // What clients paid (docs/spec/02, "Plaćanja"): recorded, never processed.
                Route::get('/payments/summary', [PaymentController::class, 'summary'])->name('payments.summary');
                Route::get('/payments/export', [PaymentController::class, 'export'])->name('payments.export');
                Route::post('/payments', [PaymentController::class, 'store'])->middleware('idempotent')->name('payments.store');
                Route::apiResource('payments', PaymentController::class)->except('store');

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
});
