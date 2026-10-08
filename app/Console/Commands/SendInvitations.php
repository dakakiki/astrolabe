<?php

namespace App\Console\Commands;

use App\Actions\Invitations\SendInvitation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Invites people to the closed beta (Phase 8a): one link per address, valid
 * once and for a limited time. A new invitation replaces the address's
 * earlier open one. The link is printed too, in case the email does not arrive.
 * The admin's Invitations screen does the same (Phase 8c).
 */
class SendInvitations extends Command
{
    protected $signature = 'invitations:send
        {emails* : One or more email addresses}
        {--days= : How long the link works (default: astrolabe.registration.invitation_days)}
        {--note= : A private note, e.g. who recommended the person}';

    protected $description = 'Email closed-beta invitations to register';

    public function handle(SendInvitation $send): int
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

            ['invitation' => $invitation, 'link' => $link, 'sent' => $sent] = $send->handle($email, $days, $this->option('note'));

            if ($sent) {
                $this->info("{$email}: invitation sent, valid until {$invitation->expires_at->toDateTimeString()} UTC.");
            } else {
                $this->error("{$email}: the email could not be sent (see the log). The link below still works.");
                $failed = true;
            }

            $this->line('  '.$link);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
