<?php

namespace App\Console\Commands;

use App\Models\RegistrationInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The closed beta at a glance: who was invited, and who has an account now.
 */
class ListInvitations extends Command
{
    protected $signature = 'invitations:list {--open : Only invitations that still work}';

    protected $description = 'List closed-beta invitations';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $invitations = RegistrationInvitation::query()
            ->when($this->option('open'), fn ($query) => $query->open())
            ->orderByDesc('id')
            ->get();

        if ($invitations->isEmpty()) {
            $this->info('No invitations.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Status', 'Sent (UTC)', 'Valid until (UTC)', 'Accepted (UTC)', 'Note'],
            $invitations->map(fn (RegistrationInvitation $invitation) => [
                $invitation->email,
                $invitation->status($now),
                $invitation->created_at?->toDateTimeString(),
                $invitation->expires_at->toDateTimeString(),
                $invitation->accepted_at?->toDateTimeString() ?? '',
                $invitation->note ?? '',
            ]),
        );

        return self::SUCCESS;
    }
}
