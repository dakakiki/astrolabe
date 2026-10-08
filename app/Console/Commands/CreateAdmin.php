<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Notifications\AdminWelcome;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes the operator's admin account (Phase 8c) — the only way one comes to
 * be. It is a separate account: a new address, never an astrologer's, never a
 * member of a practice. No password goes through the terminal: the account
 * gets a link to set one (emailed and printed), and the admin opens only once
 * two-factor sign-in is on.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email : A new address, not an astrologer\'s} {--name=Operator : The name shown in the audit log}';

    protected $description = 'Create the operator\'s admin account and send it a link to set the password';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name')) ?: 'Operator';

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("{$email}: not an email address.");

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("{$email} already has an account. An admin needs an address of its own.");

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        Audit::system(AuditEvent::AdminCreated, $user);

        $token = Password::broker()->createToken($user);
        $link = url('/reset-password/'.$token).'?'.http_build_query(['email' => $email]);

        try {
            $user->notifyNow(new AdminWelcome($link));
            $this->info("{$email}: admin account created; the email with a link to set the password is on its way.");
        } catch (Throwable $e) {
            $this->warn("{$email}: admin account created, but the email could not be sent (".$e::class.'). Use the link below.');
        }

        $this->line('  '.$link);
        $this->line('  The link works for '.config('auth.passwords.users.expire', 60).' minutes; after signing in, turn on two-factor sign-in.');

        return self::SUCCESS;
    }
}
