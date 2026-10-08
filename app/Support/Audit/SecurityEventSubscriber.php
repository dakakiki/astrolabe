<?php

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Turns the framework's and Fortify's authentication events into audit log
 * entries. Registered in AppServiceProvider (not in app/Listeners, where event
 * discovery would register the methods a second time).
 *
 * Credentials are never read from the events: a failed sign-in records the
 * account it was aimed at, if there is one, and nothing about the attempt.
 */
class SecurityEventSubscriber
{
    public function onLogin(Login $event): void
    {
        // "Keep me signed in" brings a person back without a password; that is a sign-in too.
        Audit::record(AuditEvent::Login, properties: ['remembered' => $event->remember ?: null], user: $event->user);
    }

    public function onFailed(Failed $event): void
    {
        Audit::record(AuditEvent::LoginFailed, user: $event->user);
    }

    public function onLockout(Lockout $event): void
    {
        $email = $event->request->input(Fortify::username());
        $user = is_string($email) ? User::query()->where('email', mb_strtolower($email))->first() : null;

        Audit::record(AuditEvent::Lockout, user: $user);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user !== null) {
            Audit::record(AuditEvent::Logout, user: $event->user);
        }
    }

    public function onRegistered(Registered $event): void
    {
        Audit::record(AuditEvent::Registered, user: $event->user);
    }

    public function onVerified(Verified $event): void
    {
        Audit::record(AuditEvent::EmailVerified, user: $event->user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        Audit::record(AuditEvent::PasswordReset, user: $event->user);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record(AuditEvent::TwoFactorEnabled, user: $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record(AuditEvent::TwoFactorDisabled, user: $event->user);
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        Audit::record(AuditEvent::TwoFactorFailed, user: $event->user);
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        // Enabling two-factor generates the first set; only a later, deliberate new set counts.
        if ($event->user->two_factor_confirmed_at !== null) {
            Audit::record(AuditEvent::RecoveryCodesRegenerated, user: $event->user);
        }
    }

    public function onRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        Audit::record(AuditEvent::RecoveryCodeUsed, user: $event->user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            RecoveryCodesGenerated::class => 'onRecoveryCodesGenerated',
            RecoveryCodeReplaced::class => 'onRecoveryCodeReplaced',
        ];
    }
}
