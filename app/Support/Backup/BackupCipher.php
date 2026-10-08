<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Compresses and encrypts a backup file, and back (docs/spec/06, "enkriptovani
 * backup"). libsodium's secretstream (XChaCha20-Poly1305) works chunk by chunk,
 * so a file of any size goes through in constant memory, and every chunk is
 * authenticated: a changed, cut or reordered file fails to decrypt instead of
 * restoring something wrong. gzip runs before encryption (encrypted data does
 * not compress).
 *
 * File layout: the magic line, the 24-byte stream header, then frames of a
 * 4-byte big-endian length and that many encrypted bytes; the last frame
 * carries the FINAL tag.
 *
 * The key is BACKUP_KEY: 32 random bytes as "base64:…" (`backup:key`). It is
 * the only way to read a backup — keep a copy away from the server.
 */
final class BackupCipher
{
    public const MAGIC = "ASTROLABE-BACKUP-1\n";

    private const CHUNK = 1024 * 1024;

    private const MAX_FRAME = 64 * 1024 * 1024;

    /** A new key for BACKUP_KEY. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** The configured key as raw bytes; fails when it is missing or malformed. */
    public static function key(?string $configured = null): string
    {
        $configured ??= (string) config('astrolabe.backup.key');
        $raw = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_KEY is missing or not a key made by `php artisan backup:key`.');
        }

        return $raw;
    }

    public static function encrypt(string $source, string $target, string $key): void
    {
        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.$header);

            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                $compressed = deflate_add($deflate, $chunk === false ? '' : $chunk, ZLIB_NO_FLUSH);

                if ($compressed !== '') {
                    self::frame($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $compressed));
                }
            }

            $last = deflate_add($deflate, '', ZLIB_FINISH);
            self::frame($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $last,
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            ));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $source, string $target, string $key): void
    {
        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This is not an AstroLabe backup file.');
            }

            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if ($header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('The backup file is cut short.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            $finished = false;

            while (! $finished) {
                $length = fread($in, 4);

                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('The backup file is cut short.');
                }

                $size = unpack('N', $length)[1];

                if ($size > self::MAX_FRAME) {
                    throw new RuntimeException('The backup file is damaged.');
                }

                $frame = $size > 0 ? self::readExactly($in, $size) : '';
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);

                if ($result === false) {
                    throw new RuntimeException('The backup cannot be decrypted: wrong key, or the file was changed.');
                }

                [$plain, $tag] = $result;
                fwrite($out, inflate_add($inflate, $plain, ZLIB_SYNC_FLUSH));
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (fread($in, 1) !== '') {
                throw new RuntimeException('The backup file has data after its end.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @param  resource  $out
     */
    private static function frame($out, string $ciphertext): void
    {
        fwrite($out, pack('N', strlen($ciphertext)).$ciphertext);
    }

    /**
     * @param  resource  $in
     */
    private static function readExactly($in, int $size): string
    {
        $data = '';

        while (strlen($data) < $size && ! feof($in)) {
            $data .= fread($in, $size - strlen($data));
        }

        if (strlen($data) !== $size) {
            throw new RuntimeException('The backup file is cut short.');
        }

        return $data;
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }
}
