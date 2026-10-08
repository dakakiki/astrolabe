<?php

namespace App\Support\Operations;

/**
 * Which version of the application runs: APP_VERSION when set, otherwise the
 * Git commit the deploy checked out (`git pull` on the server, docs/deployment.md).
 * Shown in the admin's System screen and stored with feedback.
 */
final class AppVersion
{
    public static function current(): ?string
    {
        if (filled(env('APP_VERSION'))) {
            return (string) env('APP_VERSION');
        }

        $git = base_path('.git');
        $head = @file_get_contents($git.'/HEAD');

        if (! is_string($head)) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $commit = @file_get_contents($git.'/'.$ref);

            if (! is_string($commit)) {
                $commit = self::packedRef($git, $ref);
            }
        } else {
            $commit = $head;
        }

        return is_string($commit) && preg_match('/^[0-9a-f]{7,40}/', trim($commit), $match) === 1
            ? substr($match[0], 0, 7)
            : null;
    }

    private static function packedRef(string $git, string $ref): ?string
    {
        $packed = @file($git.'/packed-refs', FILE_IGNORE_NEW_LINES);

        foreach ($packed ?: [] as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }
}
