<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups on the server (docs/spec/06, "Pouzdanost"; docs/deployment.md,
 * "Backup"). One folder per backup under astrolabe.backup.path:
 *
 *   backup-20261009-020000/
 *     database.sql.gz.enc   the database without the GeoNames rows
 *     files.zip.gz.enc      the client files (local disk only)
 *     manifest.json         when, sizes, checksums, row counts — no client data
 *
 * The newest `keep` folders stay. Nothing is written unencrypted except for
 * the moments a temporary file exists next to the backups (same private
 * folder), deleted when the step ends. Copying backups away from the server
 * comes with production (Phase 8d).
 */
final class Backups
{
    public const FORMAT = 'astrolabe-backup';

    public const VERSION = 1;

    private const NAME = '/^backup-\d{8}-\d{6}$/';

    /** Tables whose row counts the manifest records, to compare after a restore. */
    private const COUNTED = [
        'users', 'workspaces', 'clients', 'client_birth_details', 'consultations', 'notes', 'attachments',
        'appointments', 'tasks', 'payments', 'related_people', 'chart_calculations', 'audit_logs', 'migrations',
    ];

    public function __construct(private readonly DatabaseDump $dump) {}

    /**
     * Makes a backup now and removes the ones beyond `keep`.
     *
     * @return array<string, mixed> the manifest
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $key = BackupCipher::key();
        $now ??= CarbonImmutable::now('UTC');
        $name = 'backup-'.$now->format('Ymd-His');
        $folder = $this->path($name);

        if (is_dir($folder)) {
            throw new RuntimeException("A backup named {$name} already exists.");
        }

        File::ensureDirectoryExists($folder, 0700);

        try {
            $counts = $this->rowCounts();
            $database = $this->encrypted($folder, 'database.sql.gz.enc', $key, fn (string $plain) => $this->dump->dump($plain));
            $files = $this->filesArchive($folder, $key);

            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'name' => $name,
                'created_at' => $now->toIso8601ZuluString(),
                'environment' => app()->environment(),
                'database' => $database + [
                    'name' => config('database.connections.'.config('database.default').'.database'),
                    'structure_only' => config('astrolabe.backup.structure_only'),
                    'migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
                    'rows' => $counts,
                ],
                'files' => $files,
            ];

            file_put_contents($folder.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } catch (Throwable $e) {
            File::deleteDirectory($folder);

            throw $e;
        }

        $this->prune(config('astrolabe.backup.keep'));

        return $manifest;
    }

    /**
     * Backups, newest first, with their manifests (null for an unfinished one).
     *
     * @return Collection<int, array{name: string, manifest: array<string, mixed>|null}>
     */
    public function all(): Collection
    {
        $root = config('astrolabe.backup.path');

        if (! is_dir($root)) {
            return collect();
        }

        return collect(File::directories($root))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match(self::NAME, $name) === 1)
            ->sortDesc()
            ->values()
            ->map(fn (string $name) => ['name' => $name, 'manifest' => $this->manifest($name)]);
    }

    /** The newest finished backup's manifest, if any. */
    public function latest(): ?array
    {
        return $this->all()->first(fn (array $backup) => $backup['manifest'] !== null)['manifest'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(string $name): ?array
    {
        $file = $this->path($name).'/manifest.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /**
     * Loads a backup's database into `$database` and, when asked, unpacks its
     * client files into `$filesTo`. Checksums are compared before anything is
     * decrypted.
     *
     * @return array<string, mixed> the manifest
     */
    public function restore(string $name, string $database, ?string $filesTo = null): array
    {
        $manifest = $this->manifest($name) ?? throw new RuntimeException("There is no finished backup named {$name}.");
        $key = BackupCipher::key();
        $folder = $this->path($name);

        $this->verifyChecksum($folder, $manifest['database']);
        $this->decrypted($folder, $manifest['database']['file'], $key, fn (string $sql) => $this->dump->restore($sql, $database));

        if ($filesTo !== null && ($manifest['files']['file'] ?? null) !== null) {
            $this->verifyChecksum($folder, $manifest['files']);
            $this->decrypted($folder, $manifest['files']['file'], $key, function (string $zipPath) use ($filesTo) {
                File::ensureDirectoryExists($filesTo, 0700);
                $zip = new ZipArchive;

                if ($zip->open($zipPath) !== true || ! $zip->extractTo($filesTo) || ! $zip->close()) {
                    throw new RuntimeException('The client files could not be unpacked.');
                }
            });
        }

        return $manifest;
    }

    /**
     * A restored copy seen the way the application would see it: through its
     * own connection to that database (on the same server). Row counts of the
     * counted tables — null for a missing one — and whether `$migration` ran there.
     *
     * @return array{rows: array<string, int|null>, migration: bool}
     */
    public function inspect(string $database, ?string $migration): array
    {
        DatabaseDump::guardName($database);

        $default = config('database.default');
        config(['database.connections.restore_check' => ['database' => $database] + config("database.connections.{$default}")]);

        try {
            $connection = DB::connection('restore_check');
            $present = collect($connection->select('select table_name as name from information_schema.tables where table_schema = ?', [$database]))
                ->pluck('name')
                ->flip();

            return [
                'rows' => collect(self::COUNTED)->mapWithKeys(fn (string $table) => [
                    $table => $present->has($table) ? $connection->table($table)->count() : null,
                ])->all(),
                'migration' => $migration === null
                    || ($present->has('migrations') && $connection->table('migrations')->where('migration', $migration)->exists()),
            ];
        } finally {
            DB::purge('restore_check');
        }
    }

    /** Removes all but the newest `$keep` backups (unfinished ones count as old). */
    public function prune(int $keep): int
    {
        $old = $this->all()->slice(max($keep, 1));

        foreach ($old as $backup) {
            File::deleteDirectory($this->path($backup['name']));
        }

        return $old->count();
    }

    public function path(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new RuntimeException("{$name} is not a backup name.");
        }

        return config('astrolabe.backup.path').DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(self::COUNTED)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * The client files as a ZIP (stored, not compressed twice), then encrypted.
     * A bucket keeps its own copies; only a local disk is archived here.
     *
     * @return array<string, mixed>|null
     */
    private function filesArchive(string $folder, string $key): ?array
    {
        $disk = config('astrolabe.attachments.disk');

        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null;
        }

        $storage = Storage::disk($disk);
        $files = $storage->allFiles();

        // ZipArchive writes no file for an empty archive; there is nothing to restore either.
        if ($files === []) {
            return ['file' => null, 'bytes' => 0, 'sha256' => null, 'count' => 0];
        }

        return $this->encrypted($folder, 'files.zip.gz.enc', $key, function (string $plain) use ($storage, $files) {
            $zip = new ZipArchive;

            if ($zip->open($plain, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The client files archive could not be created.');
            }

            foreach ($files as $file) {
                $zip->addFile($storage->path($file), $file);
                $zip->setCompressionName($file, ZipArchive::CM_STORE);
            }

            if ($zip->close() !== true) {
                throw new RuntimeException('The client files archive could not be written.');
            }
        }) + ['count' => count($files)];
    }

    /**
     * Writes a plain temporary file with `$write`, encrypts it into `$file`
     * and removes the plain one.
     *
     * @param  callable(string): void  $write
     * @return array{file: string, bytes: int, sha256: string}
     */
    private function encrypted(string $folder, string $file, string $key, callable $write): array
    {
        $plain = $folder.'/.plain-'.bin2hex(random_bytes(6));

        try {
            $write($plain);
            BackupCipher::encrypt($plain, $folder.'/'.$file, $key);
        } finally {
            @unlink($plain);
        }

        return [
            'file' => $file,
            'bytes' => filesize($folder.'/'.$file),
            'sha256' => hash_file('sha256', $folder.'/'.$file),
        ];
    }

    /**
     * @param  callable(string): void  $use
     */
    private function decrypted(string $folder, string $file, string $key, callable $use): void
    {
        $plain = $folder.'/.plain-'.bin2hex(random_bytes(6));

        try {
            BackupCipher::decrypt($folder.'/'.$file, $plain, $key);
            $use($plain);
        } finally {
            @unlink($plain);
        }
    }

    /**
     * @param  array{file: string, sha256: string}  $part
     */
    private function verifyChecksum(string $folder, array $part): void
    {
        $path = $folder.'/'.$part['file'];

        if (! is_file($path) || ! hash_equals($part['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException("{$part['file']} does not match the manifest: the backup is incomplete or was changed.");
        }
    }
}
