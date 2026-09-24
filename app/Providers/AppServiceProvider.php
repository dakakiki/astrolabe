<?php

namespace App\Providers;

use App\Astrology\Contracts\Geocoder;
use App\Astrology\Geocoding\LocalGeoNamesGeocoder;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
