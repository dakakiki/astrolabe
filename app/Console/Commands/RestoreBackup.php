<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\Audit;
use App\Support\Backup\Backups;
use App\Support\Backup\DatabaseDump;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restores an encrypted backup into a database on the same server, and the
 * client files into a folder when asked. Restoring over the application's own
 * database needs --force and a confirmation.
 *
 * --verify is the trial restore (docs/spec/06, "periodičan probni restore"):
 * into a scratch database, row counts set beside the backup's own, the latest
 * migration present, then the scratch database is dropped (unless --keep).
 */
class RestoreBackup extends Command
{
    protected $signature = 'backup:restore
        {backup=latest : The backup name from backup:list, or "latest"}
        {--database= : The database to restore into (created when missing)}
        {--files-to= : A folder to unpack the client files into}
        {--verify : Trial restore into a scratch database, compared and dropped}
        {--keep : With --verify, keep the scratch database}
        {--force : Allow restoring over the application\'s own database}';

    protected $description = 'Restore an encrypted backup (or try one out with --verify)';

    public function handle(Backups $backups, DatabaseDump $dump): int
    {
        $name = $this->argument('backup') === 'latest'
            ? ($backups->latest()['name'] ?? null)
            : $this->argument('backup');

        if ($name === null) {
            $this->error('There is no finished backup.');

            return self::FAILURE;
        }

        $live = config('database.connections.'.config('database.default').'.database');
        $verify = (bool) $this->option('verify');
        $database = $verify ? $live.'_restore_check' : $this->option('database');

        if (! is_string($database) || $database === '') {
            $this->error('Name the database to restore into with --database (or use --verify).');

            return self::FAILURE;
        }

        if ($database === $live && ! ($this->option('force') && $this->confirm("Replace the data in the application's own database \"{$live}\" with {$name}?"))) {
            $this->error("Not restoring over \"{$live}\" without --force and a confirmation.");

            return self::FAILURE;
        }

        try {
            if ($verify) {
                $dump->drop($database);
            }

            $manifest = $backups->restore($name, $database, $verify ? null : $this->option('files-to'));
        } catch (Throwable $e) {
            $this->error('The restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Audit::system(AuditEvent::BackupRestored, properties: [
            'backup' => $name,
            'over_live_database' => $database === $live ?: null,
            'trial' => $verify ?: null,
        ]);

        $this->info("Restored {$name} into \"{$database}\".");

        return $verify ? $this->compare($backups, $dump, $manifest, $database) : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function compare(Backups $backups, DatabaseDump $dump, array $manifest, string $database): int
    {
        $migration = $manifest['database']['migration'];
        ['rows' => $restored, 'migration' => $hasMigration] = $backups->inspect($database, $migration);
        $missing = array_keys(array_filter($restored, fn (?int $count) => $count === null));

        $this->table(['Table', 'In the backup', 'Restored'], collect($manifest['database']['rows'])
            ->map(fn (int $count, string $table) => [$table, $count, $restored[$table] ?? 'missing'])
            ->values()
            ->all());

        // Rows written while the dump ran may differ by a few; a missing table or migration may not.
        $ok = $missing === [] && $hasMigration;

        if (! $this->option('keep')) {
            $dump->drop($database);
        }

        $ok ? $this->info('The trial restore worked.') : $this->error('The trial restore is incomplete: '.implode(', ', [...$missing, ...($hasMigration ? [] : ['migration '.$migration])]));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
