<?php

namespace Tests\Unit;

use App\Astrology\Engines\SwissEphemerisEngine;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The engine's version and data-file checksums are part of every chart's
 * input hash. They are remembered between requests, so reading a cached chart
 * starts no swetest process — and a replaced binary or data file is noticed.
 * No real swetest is needed: the process is counted, not run.
 */
class SwissEphemerisEngineVersionTest extends TestCase
{
    private string $directory;

    private string $binary;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'swisseph-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->binary = $this->directory.DIRECTORY_SEPARATOR.'swetest';
        file_put_contents($this->binary, 'binary');
        touch($this->binary, time() - 3600);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'sepl_18.se1', 'planets');
        touch($this->directory.DIRECTORY_SEPARATOR.'sepl_18.se1', time() - 3600);

        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.DIRECTORY_SEPARATOR.'*'));
        rmdir($this->directory);

        parent::tearDown();
    }

    /** An engine as a new request gets it, whose swetest only counts its runs. */
    private function engine(string $help = "Swetest computes a complete set of geocentric planetary positions,\nVersion: 2.10.03\n"): SwissEphemerisEngine
    {
        return new class($this->binary, $this->directory, 10, $this->cache, $help) extends SwissEphemerisEngine
        {
            public int $runs = 0;

            public function __construct(string $binary, string $path, int $timeout, Repository $cache, private readonly string $help)
            {
                parent::__construct($binary, $path, $timeout, $cache);
            }

            protected function run(array $arguments, bool $allowWarnings = false): string
            {
                $this->runs++;

                return $this->help;
            }
        };
    }

    public function test_the_version_is_asked_once_and_remembered_for_later_requests(): void
    {
        $first = $this->engine();
        $this->assertSame('2.10.03', $first->version());
        $this->assertSame('2.10.03', $first->version());
        $this->assertSame(1, $first->runs);

        // The next request builds its own engine: a cached chart is read without starting swetest.
        $next = $this->engine();
        $this->assertSame('Swiss Ephemeris 2.10.03 sepl_18.se1:'.hash('crc32b', 'planets'), $next->fingerprint());
        $this->assertSame(0, $next->runs);
    }

    public function test_a_replaced_binary_is_asked_again(): void
    {
        $this->engine()->version();

        file_put_contents($this->binary, 'a newer binary');
        touch($this->binary, time());

        $after = $this->engine("Version: 2.10.04\n");
        $this->assertSame('2.10.04', $after->version());
        $this->assertSame(1, $after->runs);
    }

    public function test_a_version_that_cannot_be_read_is_not_remembered(): void
    {
        $this->assertSame('unknown', $this->engine('usage: swetest …')->version());

        $next = $this->engine();
        $this->assertSame('2.10.03', $next->version());
        $this->assertSame(1, $next->runs);
    }

    public function test_a_replaced_data_file_changes_the_fingerprint(): void
    {
        $before = $this->engine()->fingerprint();

        $file = $this->directory.DIRECTORY_SEPARATOR.'sepl_18.se1';
        file_put_contents($file, 'newer planets');
        touch($file, time());

        $after = $this->engine()->fingerprint();
        $this->assertNotSame($before, $after);
        $this->assertStringEndsWith('sepl_18.se1:'.hash('crc32b', 'newer planets'), $after);
    }
}
