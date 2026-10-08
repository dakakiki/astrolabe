<?php

namespace App\Console\Commands;

use App\Support\Backup\Backups;
use Illuminate\Console\Command;

class ListBackups extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'List the encrypted backups on this server, newest first';

    public function handle(Backups $backups): int
    {
        $rows = $backups->all()->map(fn (array $backup) => [
            $backup['name'],
            $backup['manifest']['created_at'] ?? 'unfinished',
            isset($backup['manifest']) ? round($backup['manifest']['database']['bytes'] / 1024).' KB' : '',
            $backup['manifest']['files']['count'] ?? '',
            $backup['manifest']['database']['migration'] ?? '',
        ]);

        if ($rows->isEmpty()) {
            $this->info('No backups yet.');

            return self::SUCCESS;
        }

        $this->table(['Backup', 'Made (UTC)', 'Database', 'Files', 'Last migration'], $rows->all());

        return self::SUCCESS;
    }
}
