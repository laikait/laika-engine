<?php

declare(strict_types=1);

namespace Laika\Engine\Cache\Tests\Unit;

use Laika\Engine\Cache\Contracts\CacheDriverInterface;
use Laika\Engine\Cache\Driver\ArrayDriver;
use Laika\Engine\Cache\Driver\FileDriver;
use Laika\Engine\Cache\Driver\MemcachedDriver;
use Laika\Engine\Cache\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

/**
 * One suite, every driver.
 *
 * A cache whose backends disagree about what a miss is, or about whether a
 * stored false is cached, is worse than no cache: the bug only appears on the
 * backend you did not develop against. So the contract is asserted against all
 * of them rather than against the one that happens to be configured.
 *
 * redis and memcached are skipped when the extension or the server is absent,
 * which is the normal case on a developer machine.
 */
final class DriverConformanceTest extends TestCase
{
    /** @var string Scratch directory for the file driver */
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/laika-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        foreach ((array) glob($this->path . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->path);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function drivers(): array
    {
        return [
            'array'     => ['array'],
            'file'      => ['file'],
            'redis'     => ['redis'],
            'memcached' => ['memcached'],
        ];
    }

    /**
     * Every driver that stores bytes rather than live values.
     * @return array<string,array{0:string}>
     */
    public static function serializingDrivers(): array
    {
        $drivers = self::drivers();
        unset($drivers['array']);

        return $drivers;
    }

    private function make(string $name, int $defaultTtl = 0): CacheDriverInterface
    {
        $prefix = 'laikatest' . bin2hex(random_bytes(4));

        switch ($name) {
            case 'array':
                return new ArrayDriver($defaultTtl);

            case 'file':
                return new FileDriver($this->path, $defaultTtl);

            case 'redis':
                if (!extension_loaded('redis')) {
                    self::markTestSkipped('ext-redis is not loaded.');
                }

                $client = new \Redis();

                try {
                    if (!@$client->connect('127.0.0.1', 6379, 0.5)) {
                        self::markTestSkipped('No Redis server on 127.0.0.1:6379.');
                    }
                } catch (\Throwable) {
                    self::markTestSkipped('No Redis server on 127.0.0.1:6379.');
                }

                return new RedisDriver($client, $prefix, $defaultTtl);

            case 'memcached':
                if (!extension_loaded('memcached')) {
                    self::markTestSkipped('ext-memcached is not loaded.');
                }

                $client = new \Memcached();
                $client->addServer('127.0.0.1', 11211);

                // addServer() does not connect, so this is the only way to find
                // out whether anything is actually listening.
                if ($client->set($prefix . ':ping', 1) === false) {
                    self::markTestSkipped('No Memcached server on 127.0.0.1:11211.');
                }

                return new MemcachedDriver($client, $prefix, $defaultTtl);
        }

        self::fail("Unknown driver [{$name}].");
    }

    /*=========================== RULE 1: A MISS IS NOT NULL ===========================*/

    /** @dataProvider drivers */
    public function testAMissReturnsTheDefault(string $name): void
    {
        $driver = $this->make($name);

        self::assertNull($driver->get('absent'));
        self::assertSame('fallback', $driver->get('absent', 'fallback'));
    }

    /** @dataProvider drivers */
    public function testAStoredNullIsNotAMiss(string $name): void
    {
        // The bug this exists to prevent: RedisStorage returns null both for
        // "no such key" and for a stored null, so the caller recomputes a
        // legitimately-null value on every single request, forever.
        $driver = $this->make($name);
        $driver->set('nothing', null);

        self::assertTrue($driver->has('nothing'));
        self::assertNull($driver->get('nothing', 'fallback'));
    }

    /** @dataProvider drivers */
    public function testAStoredFalseIsNotAMiss(string $name): void
    {
        $driver = $this->make($name);
        $driver->set('no', false);

        self::assertTrue($driver->has('no'));
        self::assertFalse($driver->get('no', 'fallback'));
    }

    /** @dataProvider drivers */
    public function testAStoredZeroAndEmptyStringSurvive(string $name): void
    {
        $driver = $this->make($name);
        $driver->set('zero', 0);
        $driver->set('empty', '');

        self::assertSame(0, $driver->get('zero', 'fallback'));
        self::assertSame('', $driver->get('empty', 'fallback'));
    }

    /*================================ ROUND TRIPPING =================================*/

    /** @dataProvider drivers */
    public function testScalarsAndArraysRoundTrip(string $name): void
    {
        $driver = $this->make($name);
        $value = ['a' => 1, 'b' => [true, 2.5, 'three'], 'c' => null];

        $driver->set('mixed', $value);

        self::assertSame($value, $driver->get('mixed'));
    }

    /** @dataProvider serializingDrivers */
    public function testASerializingDriverDoesNotInstantiateObjectsByDefault(string $name): void
    {
        // allowed_classes is false unless the application opts in, so an entry
        // holding an object is inert on the way back rather than instantiated.
        // A cache is shared, writable state -- on Redis possibly writable by
        // anything else on that server -- and unserializing arbitrary classes
        // out of it is an object-injection gadget chain.
        $driver = $this->make($name);
        $driver->set('obj', new \stdClass());

        self::assertNotInstanceOf(\stdClass::class, $driver->get('obj'));
    }

    public function testTheArrayDriverKeepsTheObjectItWasGiven(): void
    {
        // Not an exception to the rule above but the absence of one: nothing is
        // serialized, so the object never leaves the process and there is no
        // untrusted input to defend against.
        $driver = $this->make('array');
        $object = new \stdClass();

        $driver->set('obj', $object);

        self::assertSame($object, $driver->get('obj'));
    }

    /*==================================== has() ======================================*/

    /** @dataProvider drivers */
    public function testHasIsAboutPresenceNotTruthiness(string $name): void
    {
        $driver = $this->make($name);

        self::assertFalse($driver->has('k'));

        foreach ([null, false, 0, '', []] as $i => $falsy) {
            $driver->set("k{$i}", $falsy);
            self::assertTrue($driver->has("k{$i}"), var_export($falsy, true));
        }
    }

    /*================================ pop() / flush() ================================*/

    /** @dataProvider drivers */
    public function testPopRemovesTheKey(string $name): void
    {
        $driver = $this->make($name);
        $driver->set('gone', 'value');

        self::assertTrue($driver->pop('gone'));
        self::assertFalse($driver->has('gone'));
        self::assertNull($driver->get('gone'));
    }

    /** @dataProvider drivers */
    public function testPoppingAnAbsentKeySucceeds(string $name): void
    {
        self::assertTrue($this->make($name)->pop('never-existed'));
    }

    /** @dataProvider drivers */
    public function testFlushEmptiesTheStore(string $name): void
    {
        $driver = $this->make($name);
        $driver->set('a', 1);
        $driver->set('b', 2);

        self::assertTrue($driver->flush());
        self::assertFalse($driver->has('a'));
        self::assertFalse($driver->has('b'));
    }

    /*====================================== TTL ======================================*/

    /** @dataProvider drivers */
    public function testAnExpiredEntryReadsAsAMiss(string $name): void
    {
        $driver = $this->make($name);

        // Real time: Redis and Memcached expire keys themselves, so this cannot
        // be faked by moving a clock.
        $driver->set('brief', 'value', 1);
        self::assertTrue($driver->has('brief'));

        sleep(2);

        self::assertFalse($driver->has('brief'));
        self::assertSame('fallback', $driver->get('brief', 'fallback'));
    }

    /** @dataProvider drivers */
    public function testZeroTtlDoesNotExpire(string $name): void
    {
        // 0 is explicit "forever", and must beat the driver's default TTL
        $driver = $this->make($name, 1);
        $driver->set('kept', 'value', 0);

        sleep(2);

        self::assertTrue($driver->has('kept'));
    }

    /** @dataProvider drivers */
    public function testTheDefaultTtlAppliesWhenNoneIsGiven(string $name): void
    {
        $driver = $this->make($name, 1);
        $driver->set('inherits', 'value');

        sleep(2);

        self::assertFalse($driver->has('inherits'));
    }

    /*================================== increment() ==================================*/

    /** @dataProvider drivers */
    public function testIncrementTreatsAMissingKeyAsZero(string $name): void
    {
        $driver = $this->make($name);

        self::assertSame(1, $driver->increment('hits'));
        self::assertSame(3, $driver->increment('hits', 2));
        self::assertSame(2, $driver->decrement('hits'));
    }

    /** @dataProvider drivers */
    public function testIncrementRefusesANonIntegerEntry(string $name): void
    {
        $driver = $this->make($name);
        $driver->set('word', 'not a number');

        self::assertFalse($driver->increment('word'));
    }

    /** @dataProvider drivers */
    public function testIncrementDoesNotRenewTheExpiry(string $name): void
    {
        // A counter that renewed its TTL on every increment would never expire
        // under steady traffic -- exactly what a rate limiter must not do.
        $driver = $this->make($name);
        $driver->set('window', 1, 1);
        $driver->increment('window');

        sleep(2);

        self::assertFalse($driver->has('window'));
    }
}
