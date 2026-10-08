<?php

namespace App\Console\Commands;

use App\Support\Backup\Backups;
use Illuminate\Console\Command;
use Throwable;

/**
 * The nightly encrypted backup (Backups). A failure is reported, which emails
 * the operator (OperatorAlerts); a missing backup also fails the health check.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Make an encrypted backup of the database and the client files';

    public function handle(Backups $backups): int
    {
        try {
            $manifest = $backups->run();
        } catch (Throwable $e) {
            report($e);
            $this->error('The backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup %s: database %s, %d client file(s) %s.',
            $manifest['name'],
            $this->size($manifest['database']['bytes']),
            $manifest['files']['count'] ?? 0,
            $manifest['files'] === null ? '(kept in the bucket)' : $this->size($manifest['files']['bytes']),
        ));

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }
}
