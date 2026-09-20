<?php

declare(strict_types=1);

namespace Laika\Engine\Tests\Unit;

use Laika\Engine\Cli\EntryPoints;
use PHPUnit\Framework\TestCase;

/**
 * The `laika` and `worker` executables are how anyone runs this framework, and
 * they are written by two callers that print through different channels --
 * `php laika app:sync` and the Composer script handler. This covers the shared
 * implementation both of them call.
 */
final class EntryPointsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/laika-entry-points-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/lf-boot', 0777, true);

        // The marker that says "this is a Laika project root"
        file_put_contents($this->root . '/lf-boot/app.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->delete($this->root);
    }

    private function delete(string $path): void
    {
        if (!is_dir($path)) {
            is_file($path) && unlink($path);
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->delete($path . '/' . $entry);
        }

        rmdir($path);
    }

    /*========================== WHAT IT GENERATES ==========================*/

    public function testOneCallWritesBothExecutables(): void
    {
        $result = EntryPoints::write($this->root);

        self::assertFileExists($this->root . '/laika');
        self::assertFileExists($this->root . '/worker');
        self::assertSame(['laika', 'worker'], $result['written']);
    }

    public function testEachExecutableProxiesItsOwnBinary(): void
    {
        EntryPoints::write($this->root);

        self::assertStringContainsString(
            'vendor/laikait/laika-engine/bin/laika',
            (string) file_get_contents($this->root . '/laika')
        );
        self::assertStringContainsString(
            'vendor/laikait/laika-engine/bin/worker',
            (string) file_get_contents($this->root . '/worker')
        );
    }

    public function testBothExecutablesAreMade(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        EntryPoints::write($this->root);

        self::assertTrue(is_executable($this->root . '/laika'));
        self::assertTrue(is_executable($this->root . '/worker'));
    }

    public function testEveryStubItNeedsIsActuallyShipped(): void
    {
        // Guards the stubs/cli + stubs/queue split: a rename on one side would
        // otherwise only surface as a silently missing executable
        self::assertSame([], EntryPoints::write($this->root)['missing']);
    }

    /*============================ THE NO-OP GUARD ============================*/

    public function testNothingIsWrittenOutsideAProjectRoot(): void
    {
        // A global install, or CI for this package itself
        unlink($this->root . '/lf-boot/app.php');

        $result = EntryPoints::write($this->root);

        self::assertFileDoesNotExist($this->root . '/laika');
        self::assertFileDoesNotExist($this->root . '/worker');
        self::assertSame([], $result['written']);
        self::assertSame([], $result['removed']);
    }

    public function testATrailingSlashOnTheRootIsTolerated(): void
    {
        // app:sync passes $basePath, Composer passes dirname(vendor-dir)
        $result = EntryPoints::write($this->root . '/');

        self::assertFileExists($this->root . '/laika');
        self::assertSame(['laika', 'worker'], $result['written']);
    }

    /*============================== IDEMPOTENCE ==============================*/

    public function testASecondCallReportsNothing(): void
    {
        EntryPoints::write($this->root);

        $result = EntryPoints::write($this->root);

        self::assertSame([], $result['written'], 'An up-to-date run should report nothing.');
    }

    public function testASecondCallDoesNotRewriteTheFiles(): void
    {
        EntryPoints::write($this->root);
        $before = [filemtime($this->root . '/laika'), filemtime($this->root . '/worker')];

        EntryPoints::write($this->root);

        self::assertSame($before, [filemtime($this->root . '/laika'), filemtime($this->root . '/worker')]);
    }

    public function testAFileWhoseContentDriftedIsRewritten(): void
    {
        EntryPoints::write($this->root);
        file_put_contents($this->root . '/worker', "<?php // edited by hand\n");

        $result = EntryPoints::write($this->root);

        self::assertSame(['worker'], $result['written']);
        self::assertStringContainsString(
            'vendor/laikait/laika-engine/bin/worker',
            (string) file_get_contents($this->root . '/worker')
        );
    }

    public function testADeletedExecutableIsRestored(): void
    {
        // The repair case: `php laika app:sync` brings back a deleted worker
        EntryPoints::write($this->root);
        unlink($this->root . '/worker');

        $result = EntryPoints::write($this->root);

        self::assertFileExists($this->root . '/worker');
        self::assertSame(['worker'], $result['written']);
    }

    /*============================ LEGACY PRUNING ============================*/

    public function testBatShimsGeneratedByTheOldPackagesAreRemoved(): void
    {
        file_put_contents($this->root . '/laika.bat', "@echo off\nrem Auto-generated by laikait/laika-cli\n");
        file_put_contents($this->root . '/worker.bat', "@echo off\nrem Auto-generated by laikait/laika-queue\n");

        $result = EntryPoints::write($this->root);

        self::assertFileDoesNotExist($this->root . '/laika.bat');
        self::assertFileDoesNotExist($this->root . '/worker.bat');
        self::assertSame(['laika.bat', 'worker.bat'], $result['removed']);
    }

    public function testAHandWrittenBatIsLeftAlone(): void
    {
        // No generator marker, so it is the user's file, not ours
        file_put_contents($this->root . '/laika.bat', "@echo off\nphp laika %*\n");

        $result = EntryPoints::write($this->root);

        self::assertFileExists($this->root . '/laika.bat');
        self::assertSame([], $result['removed']);
    }

    public function testABatCarryingTheOtherPackagesMarkerIsLeftAlone(): void
    {
        // worker.bat is only ever ours when laika-queue generated it
        file_put_contents($this->root . '/worker.bat', "@echo off\nrem Auto-generated by laikait/laika-cli\n");

        $result = EntryPoints::write($this->root);

        self::assertFileExists($this->root . '/worker.bat');
        self::assertSame([], $result['removed']);
    }

    public function testNoBatIsInventedWhereNoneExisted(): void
    {
        self::assertSame([], EntryPoints::write($this->root)['removed']);
        self::assertFileDoesNotExist($this->root . '/laika.bat');
    }
}
