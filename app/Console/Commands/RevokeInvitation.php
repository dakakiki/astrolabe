<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\RegistrationInvitation;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Stops an address's open invitation from working (a link sent to the wrong
 * person, a tester who dropped out). Accounts already created stay.
 */
class RevokeInvitation extends Command
{
    protected $signature = 'invitations:revoke {email : The address the invitation was sent to}';

    protected $description = 'Revoke an open closed-beta invitation';

    public function handle(): int
    {
        $open = RegistrationInvitation::query()
            ->open()
            ->where('email', mb_strtolower(trim($this->argument('email'))))
            ->get();

        if ($open->isEmpty()) {
            $this->warn('No open invitation for that address.');

            return self::SUCCESS;
        }

        foreach ($open as $invitation) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            Audit::record(AuditEvent::InvitationRevoked, $invitation);
        }

        $this->info('Invitation revoked.');

        return self::SUCCESS;
    }
}
