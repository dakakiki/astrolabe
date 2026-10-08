<?php

namespace Tests\Unit;

use App\Support\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Backup encryption (Phase 8b): libsodium secretstream over gzip, chunk by
 * chunk; a wrong key, a changed byte or a cut file never decrypts.
 */
class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/backup-cipher-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    private function key(): string
    {
        return BackupCipher::key(BackupCipher::generateKey());
    }

    /** Encrypts `$plain` and returns the encrypted file's path. */
    private function encrypt(string $plain, string $key): string
    {
        file_put_contents($this->dir.'/plain', $plain);
        BackupCipher::encrypt($this->dir.'/plain', $this->dir.'/enc', $key);

        return $this->dir.'/enc';
    }

    private function decrypt(string $key): string
    {
        BackupCipher::decrypt($this->dir.'/enc', $this->dir.'/out', $key);

        return file_get_contents($this->dir.'/out');
    }

    public function test_a_file_comes_back_exactly_and_compressed_on_the_way(): void
    {
        $key = $this->key();
        // Several chunks of something compressible, with binary bytes in it.
        $plain = str_repeat("INSERT INTO clients VALUES (1,'Ana','Marković');\n\x00\xFF", 60000);

        $encrypted = $this->encrypt($plain, $key);

        $this->assertStringStartsWith(BackupCipher::MAGIC, file_get_contents($encrypted));
        $this->assertStringNotContainsString('Marković', file_get_contents($encrypted));
        $this->assertLessThan(strlen($plain) / 10, filesize($encrypted));
        $this->assertSame($plain, $this->decrypt($key));
    }

    public function test_an_empty_file_round_trips(): void
    {
        $key = $this->key();
        $this->encrypt('', $key);

        $this->assertSame('', $this->decrypt($key));
    }

    public function test_the_wrong_key_does_not_decrypt(): void
    {
        $this->encrypt('secret', $this->key());

        $this->expectException(RuntimeException::class);
        $this->decrypt($this->key());
    }

    public function test_a_changed_byte_does_not_decrypt(): void
    {
        $key = $this->key();
        $path = $this->encrypt(str_repeat('birth data ', 1000), $key);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 5] = chr(ord($bytes[strlen($bytes) - 5]) ^ 1);
        file_put_contents($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->decrypt($key);
    }

    public function test_a_cut_file_does_not_decrypt(): void
    {
        $key = $this->key();
        $path = $this->encrypt(random_bytes(3 * 1024 * 1024), $key);
        file_put_contents($path, substr(file_get_contents($path), 0, (int) (filesize($path) / 2)));

        $this->expectException(RuntimeException::class);
        $this->decrypt($key);
    }

    public function test_only_a_generated_key_is_accepted(): void
    {
        $this->assertSame(32, strlen(BackupCipher::key(BackupCipher::generateKey())));

        foreach (['', 'secret', 'base64:'.base64_encode('short'), base64_encode(random_bytes(32))] as $bad) {
            try {
                BackupCipher::key($bad);
                $this->fail("Accepted {$bad}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
