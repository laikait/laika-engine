<?php

declare(strict_types=1);

namespace Laika\Engine\Cache\Tests\Unit;

use Laika\Engine\Cache\Driver\FileDriver;
use PHPUnit\Framework\TestCase;

/**
 * The file driver's two concurrency claims, tested against real processes.
 *
 * Both hazards are invisible single-process: a lost increment needs two
 * writers interleaving, and a torn read needs a reader landing mid-write.
 * JsonStorage documents the first about its own read-modify-write and does not
 * solve it; this asserts that the lock here does. Replacing the locked
 * increment with get()+set() drops this from 200 to single digits.
 */
final class FileDriverConcurrencyTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/laika-cache-conc-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->path . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->path);
    }

    public function testConcurrentIncrementsLoseNothing(): void
    {
        $workers = 4;
        $each = 50;

        // The parent seeds; the children only increment. A child that seeded
        // too would reset the counter under its siblings.
        (new FileDriver($this->path))->set('counter', 0);

        $this->runToCompletion(
            $this->script('for ($i = 0; $i < ' . $each . '; $i++) { $driver->increment("counter"); }'),
            $workers
        );

        self::assertSame(
            $workers * $each,
            (new FileDriver($this->path))->get('counter'),
            'increments were lost, so the read-modify-write is not holding its lock'
        );
    }

    public function testAReaderNeverSeesAPartialWrite(): void
    {
        // Large, so a non-atomic write would be caught mid-flight
        $payload = str_repeat('abcdefghij', 20000);
        $driver = new FileDriver($this->path);
        $driver->set('big', $payload);

        $handles = $this->start($this->script(
            '$payload = str_repeat("abcdefghij", 20000);',
            'for ($i = 0; $i < 200; $i++) { $driver->set("big", $payload); }'
        ), 2);

        $reads = 0;
        $torn = 0;
        $deadline = time() + 30;

        // Every read must be the whole value or a clean miss, never a fragment
        while ($this->running($handles) && time() < $deadline) {
            $value = $driver->get('big');
            $reads++;

            if ($value !== null && $value !== $payload) {
                $torn++;
            }
        }

        $this->close($handles);

        self::assertGreaterThan(0, $reads, 'the writers finished before a single read landed');
        self::assertSame(0, $torn, "{$torn} of {$reads} reads saw a partially written entry");
    }

    /*=============================== HARNESS ===============================*/

    /**
     * A standalone PHP program using the driver against this test's directory.
     */
    private function script(string ...$body): string
    {
        return implode("\n", [
            '<?php',
            'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';',
            '$driver = new Laika\\Engine\\Cache\\Driver\\FileDriver(' . var_export($this->path, true) . ');',
            ...$body,
        ]);
    }

    /**
     * @return array<int,array{0:resource,1:string}>
     */
    private function start(string $script, int $count): array
    {
        $file = $this->path . '/worker-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $script);

        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $log = $this->path . '/worker-' . $i . '.log';
            $pipes = [];

            // Files, not pipes: nothing reads them while the loops run, and a
            // pipe that fills blocks the child indefinitely.
            $process = proc_open([PHP_BINARY, $file], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);

            if ($process === false) {
                self::markTestSkipped('proc_open() is unavailable.');
            }

            // Third slot: the exit code, once polling has seen the process end
            $handles[] = [$process, $log, null];
        }

        return $handles;
    }

    /**
     * Before PHP 8.3 the exit code is reported exactly once: by the first
     * proc_get_status() call that sees the process has ended. Every later call,
     * and proc_close(), then return -1. So the code is kept the moment it
     * appears, or a worker that exited cleanly would read as having failed.
     */
    private function running(array &$handles): bool
    {
        $running = false;

        foreach ($handles as &$handle) {
            if ($handle[2] !== null) {
                continue;
            }

            $status = proc_get_status($handle[0]);

            if (($status['running'] ?? false) === true) {
                $running = true;
            } else {
                $handle[2] = (int) ($status['exitcode'] ?? -1);
            }
        }
        unset($handle);

        return $running;
    }

    private function close(array $handles): void
    {
        foreach ($handles as [$process, $log, $exited]) {
            $closed = proc_close($process);
            // proc_close() is authoritative on 8.3+; before that it is -1 for any
            // process polling already reaped, and the recorded code is the real one
            $code = ($closed === -1 && $exited !== null) ? $exited : $closed;
            $output = is_file($log) ? trim((string) file_get_contents($log)) : '';

            self::assertSame(0, $code, "a worker exited {$code}: {$output}");
            // Any output from a worker is a warning, meaning it did not do what the test assumes
            self::assertSame('', $output, "a worker wrote output: {$output}");
        }
    }

    private function runToCompletion(string $script, int $count): void
    {
        $handles = $this->start($script, $count);
        $deadline = time() + 30;

        while ($this->running($handles) && time() < $deadline) {
            usleep(1000);
        }

        $this->close($handles);
    }
}
