<?php

namespace App\Support\Attachments;

/**
 * The largest file an upload can carry: the configured limit, or less when
 * PHP itself accepts less (upload_max_filesize, post_max_size).
 */
final class UploadLimit
{
    public static function bytes(): int
    {
        $limits = [
            config('astrolabe.attachments.max_size_mb') * 1024 * 1024,
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ];

        return (int) min(array_filter($limits, fn (?int $limit) => $limit !== null && $limit > 0));
    }

    public static function kilobytes(): int
    {
        return intdiv(self::bytes(), 1024);
    }

    /** "64M" → 67108864; null when unset or unlimited. */
    private static function iniBytes(string $key): ?int
    {
        $value = trim((string) ini_get($key));

        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
