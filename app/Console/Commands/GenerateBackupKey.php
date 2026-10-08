<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;

/**
 * Prints a new BACKUP_KEY. It is not written anywhere: the operator puts it in
 * the server's .env and keeps a copy away from the server (a password
 * manager) — without it no backup can be read.
 */
class GenerateBackupKey extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Print a new key for encrypting backups (BACKUP_KEY)';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->newLine();
        $this->warn('Put it in .env as BACKUP_KEY and keep a copy away from this server.');
        $this->warn('Backups made with a key cannot be read without that key.');

        return self::SUCCESS;
    }
}
