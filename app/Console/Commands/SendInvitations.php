<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\RegistrationInvitation;
use App\Models\User;
use App\Notifications\RegistrationInvite;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Invites people to the closed beta (Phase 8a): one link per address, valid
 * once and for a limited time. A new invitation replaces the address's
 * earlier open one. The link is printed too, in case the email does not arrive.
 */
class SendInvitations extends Command
{
    protected $signature = 'invitations:send
        {emails* : One or more email addresses}
        {--days= : How long the link works (default: astrolabe.registration.invitation_days)}
        {--note= : A private note, e.g. who recommended the person}';

    protected $description = 'Email closed-beta invitations to register';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('astrolabe.registration.invitation_days'));

        if ($days < 1 || $days > 90) {
            $this->error('--days must be between 1 and 90.');

            return self::INVALID;
        }

        $failed = false;

        foreach ($this->argument('emails') as $email) {
            $email = mb_strtolower(trim($email));

            if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
                $this->error("{$email}: not an email address.");
                $failed = true;

                continue;
            }

            if (User::query()->where('email', $email)->exists()) {
                $this->warn("{$email}: already has an account; nothing sent.");

                continue;
            }

            [$invitation, $token] = RegistrationInvitation::issue($email, $days, $this->option('note'));
            Audit::record(AuditEvent::InvitationSent, $invitation, ['days' => $days]);

            try {
                Notification::route('mail', $email)->notifyNow(new RegistrationInvite($token, $invitation->expires_at));
                $this->info("{$email}: invitation sent, valid until {$invitation->expires_at->toDateTimeString()} UTC.");
            } catch (Throwable $e) {
                $this->error("{$email}: the email could not be sent ({$e->getMessage()}). The link below still works.");
                $failed = true;
            }

            $this->line('  '.RegistrationInvitation::url($token));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
