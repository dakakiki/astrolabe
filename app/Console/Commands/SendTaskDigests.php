<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TasksDueToday;
use App\Support\Notifications\TaskDigest;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sends the morning task emails that are due (runs every minute from the
 * scheduler). A person's `next_digest_at` is moved to the next morning with one
 * conditional update before anything is sent, so the email goes out once a
 * day however often this runs. One email per practice with tasks due today.
 */
class SendTaskDigests extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Email people the morning count of tasks due today';

    public function handle(CurrentWorkspace $current): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        User::query()
            ->whereNotNull('email_verified_at')
            ->where('next_digest_at', '<=', $now)
            ->chunkById(100, function (Collection $users) use ($now, $current, &$sent) {
                foreach ($users as $user) {
                    $claimed = DB::table('users')
                        ->where('id', $user->getKey())
                        ->where('next_digest_at', $user->next_digest_at)
                        ->update(['next_digest_at' => TaskDigest::nextAt($user, $now)]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    foreach ($user->activeWorkspaces()->orderBy('workspaces.id')->get() as $workspace) {
                        $counts = $current->run($workspace, fn () => TaskDigest::counts($user, $now));

                        if ($counts['today'] > 0) {
                            $user->notify(new TasksDueToday($workspace->name, $counts['today'], $counts['overdue']));
                            $sent++;
                        }
                    }
                }
            });

        $this->info(sprintf('Queued %d morning email(s).', $sent));

        return self::SUCCESS;
    }
}
