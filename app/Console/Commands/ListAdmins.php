<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** The operator's admin accounts and whether two-factor sign-in is on (Phase 8c). */
class ListAdmins extends Command
{
    protected $signature = 'admin:list';

    protected $description = 'List the admin accounts';

    public function handle(): int
    {
        $admins = User::query()->where('is_admin', true)->orderBy('id')->get();

        if ($admins->isEmpty()) {
            $this->info('No admin accounts. Make one with `php artisan admin:create <email>`.');

            return self::SUCCESS;
        }

        $this->table(['Email', 'Name', 'Two-factor', 'Created (UTC)'], $admins->map(fn (User $user) => [
            $user->email,
            $user->name,
            $user->hasTwoFactorEnabled() ? 'on' : 'OFF — the admin stays closed',
            $user->created_at?->toDateTimeString(),
        ]));

        return self::SUCCESS;
    }
}
