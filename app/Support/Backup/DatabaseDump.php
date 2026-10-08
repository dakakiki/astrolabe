<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * The database side of a backup, through the MariaDB command-line tools
 * (config astrolabe.backup: `mariadb-dump` and `mariadb`, or their paths).
 * The password goes in a temporary option file, never on the command line
 * where other processes could read it.
 *
 * The dump is one consistent snapshot (`--single-transaction`, InnoDB) with
 * routines and triggers; the GeoNames tables come as structure only.
 */
final class DatabaseDump
{
    /** Writes the connection's database as SQL to `$target`. */
    public function dump(string $target, ?string $connection = null): void
    {
        $config = $this->config($connection);
        $database = $config['database'];

        $this->withOptionFile($config, function (string $options) use ($target, $database) {
            $command = [
                config('astrolabe.backup.dump_binary'),
                '--defaults-extra-file='.$options,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--hex-blob',
                '--skip-dump-date',
                '--default-character-set=utf8mb4',
                '--result-file='.$target,
            ];

            foreach (config('astrolabe.backup.structure_only') as $table) {
                $command[] = "--ignore-table-data={$database}.{$table}";
            }

            $command[] = $database;

            $this->run($command, 'mariadb-dump');
        });

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('The database dump came out empty.');
        }
    }

    /**
     * Loads an SQL dump into `$database` on the same server, creating it when
     * it does not exist. Nothing here stops it from being the live database —
     * the restore command asks first.
     */
    public function restore(string $sqlFile, string $database, ?string $connection = null): void
    {
        self::guardName($database);
        $config = $this->config($connection);

        $this->withOptionFile($config, function (string $options) use ($sqlFile, $database) {
            $client = config('astrolabe.backup.client_binary');

            $this->run([
                $client,
                '--defaults-extra-file='.$options,
                '--execute=CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            ], 'mariadb');

            $input = fopen($sqlFile, 'rb');

            try {
                $this->run([$client, '--defaults-extra-file='.$options, '--default-character-set=utf8mb4', $database], 'mariadb', $input);
            } finally {
                is_resource($input) && fclose($input);
            }
        });
    }

    /** Drops a scratch database (the trial restore's). */
    public function drop(string $database, ?string $connection = null): void
    {
        self::guardName($database);

        if ($database === $this->config($connection)['database']) {
            throw new InvalidArgumentException('Refusing to drop the application\'s own database.');
        }

        $this->withOptionFile($this->config($connection), fn (string $options) => $this->run([
            config('astrolabe.backup.client_binary'),
            '--defaults-extra-file='.$options,
            '--execute=DROP DATABASE IF EXISTS `'.$database.'`',
        ], 'mariadb'));
    }

    /** Letters, digits and underscores: the names this app uses, and safe to quote. */
    public static function guardName(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new InvalidArgumentException('A database name may hold only letters, digits and underscores.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(?string $connection): array
    {
        $name = $connection ?? config('database.default');
        $config = config("database.connections.{$name}");

        if (! in_array($config['driver'] ?? null, ['mariadb', 'mysql'], true)) {
            throw new RuntimeException("Backups need a MariaDB connection; \"{$name}\" is not one.");
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(string): mixed  $callback
     */
    private function withOptionFile(array $config, callable $callback): mixed
    {
        $directory = config('astrolabe.backup.path');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'.client-'.bin2hex(random_bytes(6)).'.cnf';
        $lines = ['[client]', 'user='.$this->quote((string) $config['username'])];

        if (filled($config['password'] ?? null)) {
            $lines[] = 'password='.$this->quote((string) $config['password']);
        }

        if (filled($config['unix_socket'] ?? null)) {
            $lines[] = 'socket='.$this->quote((string) $config['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) $config['host']);
            $lines[] = 'port='.(int) $config['port'];
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    /** Option-file value in double quotes (backslash and quote escaped). */
    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * @param  list<string>  $command
     * @param  resource|null  $input
     */
    private function run(array $command, string $tool, $input = null): void
    {
        $process = Process::timeout(config('astrolabe.backup.timeout'));

        if ($input !== null) {
            $process = $process->input($input);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            // The tools' own error text names tables and options, never row data.
            throw new RuntimeException("{$tool} failed (exit {$result->exitCode()}): ".trim(mb_substr($result->errorOutput(), 0, 500)));
        }
    }
}
