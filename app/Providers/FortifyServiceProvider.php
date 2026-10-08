<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RejectSuspendedAccount;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Fortify's own sign-in steps, with a suspended account stopped once its password is right (Phase 8c).
        Fortify::authenticateThrough(fn () => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            RejectSuspendedAccount::class,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // With this limiter Fortify fires no Lockout event itself; the audit log
            // gets one per locked-out minute, not one per refused attempt.
            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) use ($throttleKey) {
                if (Cache::add('lockout-recorded:'.sha1($throttleKey), true, 60)) {
                    event(new Lockout($request));
                }

                return response()->json(['message' => __('Too Many Attempts.')], 429, $headers);
            });
        });

        // Every auth endpoint (config/fortify.php "middleware"), per IP.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // The code after the password: per pending sign-in, so guessing six digits stays hopeless.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip());
        });
    }
}
