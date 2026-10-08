<?php

namespace App\Providers;

use App\Astrology\Contracts\EphemerisEngine;
use App\Astrology\Contracts\Geocoder;
use App\Astrology\Engines\FakeEngine;
use App\Astrology\Engines\SwissEphemerisEngine;
use App\Astrology\Geocoding\LocalGeoNamesGeocoder;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AstrologyMethod;
use App\Models\Attachment;
use App\Models\ChartCalculation;
use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\Consultation;
use App\Models\Note;
use App\Models\Payment;
use App\Models\RelatedPerson;
use App\Models\Service;
use App\Models\Task;
use App\Support\Audit\Audit;
use App\Support\Audit\SecurityEventSubscriber;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request or job; forgotten between them.
        $this->app->scoped(CurrentWorkspace::class);

        $this->app->bind(Geocoder::class, LocalGeoNamesGeocoder::class);

        // One engine per process; the fake one keeps the positions tests pin on it.
        // The real one keeps its version in the cache, so reading a chart starts no process.
        $this->app->singleton(EphemerisEngine::class, fn ($app) => match (config('astrolabe.ephemeris.engine')) {
            'fake' => new FakeEngine,
            default => new SwissEphemerisEngine(
                config('astrolabe.ephemeris.swetest'),
                config('astrolabe.ephemeris.path'),
                config('astrolabe.ephemeris.timeout'),
                $app->make('cache.store'),
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // NIST-style: length plus a breach check (k-anonymity: only a hash prefix leaves the
        // server). Tests skip the breach check so they never depend on the network.
        Password::defaults(function () {
            $rule = Password::min(8)->max(128);

            return $this->app->runningUnitTests() ? $rule : $rule->uncompromised();
        });

        $this->pointAuthEmailsAtTheSpa();
        $this->limitRequests();
        $this->auditCriticalOperations();

        // Stable names in polymorphic columns (chart_calculations.subject_type,
        // attachments.attachable_type, activity_events.subject_type).
        Relation::morphMap([
            'client' => Client::class,
            'related_person' => RelatedPerson::class,
            'consultation' => Consultation::class,
            'appointment' => Appointment::class,
            'note' => Note::class,
            'attachment' => Attachment::class,
            'chart_calculation' => ChartCalculation::class,
            'task' => Task::class,
        ]);
    }

    /**
     * Requests per minute and person (docs/spec/06, rate limiting). "api" covers
     * every API call; "engine" the endpoints that may start the ephemeris engine,
     * where one request costs real CPU (config/astrolabe.php, "rate_limits").
     */
    private function limitRequests(): void
    {
        $by = fn (Request $request) => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('astrolabe.rate_limits.api'))->by($by($request)));
        RateLimiter::for('engine', fn (Request $request) => Limit::perMinute(config('astrolabe.rate_limits.engine'))->by($by($request)));
    }

    /**
     * The audit log (docs/spec/06): sign-ins and account changes come from the
     * auth events; deleting practice data is recorded here, from the models,
     * whichever controller or command does it. The rest is recorded where it happens.
     */
    private function auditCriticalOperations(): void
    {
        Event::subscribe(SecurityEventSubscriber::class);

        $deletable = [
            Client::class, Consultation::class, Note::class, Attachment::class, Task::class, Payment::class,
            Service::class, RelatedPerson::class, ClientRelationship::class, AstrologyMethod::class,
        ];

        foreach ($deletable as $model) {
            $model::deleted(function (Model $record) {
                $permanently = method_exists($record, 'isForceDeleting') ? $record->isForceDeleting() : true;

                Audit::record(AuditEvent::RecordDeleted, $record, ['permanently' => $permanently]);
            });
        }
    }

    /**
     * Links in auth emails open SPA pages, which then call the API. The signed
     * verification URL is rebuilt by the SPA from the path and query below.
     */
    private function pointAuthEmailsAtTheSpa(): void
    {
        ResetPassword::createUrlUsing(function (CanResetPassword $user, string $token) {
            return url('/reset-password/'.$token).'?'.http_build_query(['email' => $user->getEmailForPasswordReset()]);
        });

        VerifyEmail::createUrlUsing(function (MustVerifyEmail $user) {
            $signed = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            );

            return url('/verify-email/'.$user->getKey().'/'.sha1($user->getEmailForVerification()))
                .'?'.parse_url($signed, PHP_URL_QUERY);
        });
    }
}
