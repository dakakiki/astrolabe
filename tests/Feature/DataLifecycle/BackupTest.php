<?php

namespace Tests\Feature\DataLifecycle;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\Backups;
use App\Support\Operations\HealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups and restores (Phase 8b). The MariaDB tools are faked here;
 * the last test runs the real ones where they are installed (locally) and
 * makes a trial restore into a scratch database.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = "-- dump\nCREATE TABLE `clients` (`id` int);\nINSERT INTO `clients` VALUES (1);\n";

    private string $path;

    /** What the faked `mariadb` received on its standard input. */
    private string $restored = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
        config([
            'astrolabe.backup.key' => BackupCipher::generateKey(),
            'astrolabe.backup.path' => $this->path,
            'astrolabe.backup.keep' => 14,
        ]);
        Storage::fake('attachments');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    /** A dump tool that writes SQL to its --result-file, and a client that reads its input. */
    private function fakeTools(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, strlen('--result-file=')), self::SQL);
                }
            }

            if (is_resource($process->input)) {
                $this->restored .= stream_get_contents($process->input);
            }

            return Process::result();
        });
    }

    private function database(): string
    {
        return config('database.connections.mariadb.database');
    }

    public function test_a_backup_holds_the_encrypted_database_and_client_files(): void
    {
        $this->fakeTools();
        Storage::disk('attachments')->put('1/2026/10/chart.pdf', '%PDF-1.4 chart');

        $this->artisan('backup:run')->assertSuccessful();

        $backup = app(Backups::class)->latest();
        $folder = $this->path.'/'.$backup['name'];
        $this->assertSame('astrolabe-backup', $backup['format']);
        $this->assertSame(['places', 'place_names', 'sessions', 'cache', 'cache_locks'], $backup['database']['structure_only']);
        $this->assertSame(1, $backup['files']['count']);
        $this->assertSame(hash_file('sha256', $folder.'/database.sql.gz.enc'), $backup['database']['sha256']);
        $this->assertGreaterThan(0, $backup['database']['rows']['migrations']);

        // Nothing readable on disk: only the encrypted files and the manifest.
        $this->assertEqualsCanonicalizing(['database.sql.gz.enc', 'files.zip.gz.enc', 'manifest.json'], array_map('basename', File::files($folder, true)));
        $this->assertStringNotContainsString('INSERT INTO', file_get_contents($folder.'/database.sql.gz.enc'));

        BackupCipher::decrypt($folder.'/database.sql.gz.enc', $this->path.'/check.sql', BackupCipher::key());
        $this->assertSame(self::SQL, file_get_contents($this->path.'/check.sql'));

        BackupCipher::decrypt($folder.'/files.zip.gz.enc', $this->path.'/check.zip', BackupCipher::key());
        $zip = new ZipArchive;
        $zip->open($this->path.'/check.zip');
        $this->assertSame('%PDF-1.4 chart', $zip->getFromName('1/2026/10/chart.pdf'));
        $zip->close();

        // One snapshot, the gazetteer as structure only, and no password on the command line.
        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, '--single-transaction')
                && str_contains($command, '--ignore-table-data='.$this->database().'.places')
                && str_contains($command, '--ignore-table-data='.$this->database().'.place_names')
                && str_contains($command, '--defaults-extra-file=')
                && ! str_contains($command, 'password');
        });
        // The option file with the credentials is gone again.
        $this->assertSame([], glob($this->path.'/.client-*') ?: []);
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        $this->fakeTools();
        config(['astrolabe.backup.keep' => 2]);
        $backups = app(Backups::class);

        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $backups->run(CarbonImmutable::parse($day.' 02:00', 'UTC'));
        }

        $this->assertSame(['backup-20261003-020000', 'backup-20261002-020000'], $backups->all()->pluck('name')->all());
    }

    public function test_without_a_key_there_is_no_backup(): void
    {
        $this->fakeTools();
        config(['astrolabe.backup.key' => null]);

        $this->artisan('backup:run')->assertFailed();

        $this->assertNull(app(Backups::class)->latest());
    }

    public function test_a_restore_loads_the_decrypted_dump_into_the_named_database(): void
    {
        $this->fakeTools();
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:restore', ['--database' => 'astrolabe_restore_target'])->assertSuccessful();

        $this->assertSame(self::SQL, $this->restored);
        Process::assertRan(fn (PendingProcess $process) => end($process->command) === 'astrolabe_restore_target');
        Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'CREATE DATABASE IF NOT EXISTS `astrolabe_restore_target`'));
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::BackupRestored)->count());
    }

    public function test_the_live_database_needs_force_and_a_bad_name_is_refused(): void
    {
        $this->fakeTools();
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:restore', ['--database' => $this->database()])->assertFailed();
        $this->artisan('backup:restore')->assertFailed();
        $this->artisan('backup:restore', ['--database' => 'x; DROP DATABASE y'])->assertFailed();

        $this->assertSame('', $this->restored);
    }

    public function test_a_changed_backup_is_not_restored(): void
    {
        $this->fakeTools();
        $this->artisan('backup:run')->assertSuccessful();
        $name = app(Backups::class)->latest()['name'];
        file_put_contents($this->path."/{$name}/database.sql.gz.enc", 'x', FILE_APPEND);

        $this->artisan('backup:restore', ['--database' => 'astrolabe_restore_target'])->assertFailed();

        $this->assertSame('', $this->restored);
    }

    public function test_the_health_check_watches_the_backups_once_a_key_is_set(): void
    {
        $this->fakeTools();

        $this->assertFalse(app(HealthCheck::class)->run()['backup']);

        $this->artisan('backup:run')->assertSuccessful();
        $this->assertTrue(app(HealthCheck::class)->run()['backup']);

        $this->travel(27)->hours();
        $this->assertFalse(app(HealthCheck::class)->run()['backup']);

        config(['astrolabe.backup.key' => null]);
        $this->assertArrayNotHasKey('backup', app(HealthCheck::class)->run());
    }

    /**
     * The real tools against the test database: dump, encrypt, restore into a
     * scratch database, compare, drop. Skipped where MariaDB's tools are not
     * installed (CI runs MariaDB in a container without them).
     */
    public function test_a_trial_restore_with_the_real_tools(): void
    {
        try {
            $available = Process::run([config('astrolabe.backup.dump_binary'), '--version'])->successful()
                && Process::run([config('astrolabe.backup.client_binary'), '--version'])->successful();
        } catch (Throwable) {
            $available = false;
        }

        if (! $available) {
            $this->markTestSkipped('MariaDB command-line tools are not installed here.');
        }

        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:restore', ['--verify' => true])
            ->expectsOutputToContain('The trial restore worked.')
            ->assertSuccessful();

        // The scratch database is gone again.
        $this->assertSame([], DB::select(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$this->database().'_restore_check'],
        ));
    }
}
