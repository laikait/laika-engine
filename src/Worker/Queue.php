<?php

declare(strict_types=1);

namespace Laika\Engine\Worker;

use RuntimeException;
use Laika\Engine\Model\Connection;
use Laika\Engine\Queue\Driver\DatabaseDriver;
use Laika\Engine\Queue\Driver\DatabaseFailedJobProvider;
use Laika\Engine\Queue\Driver\JsonDriver;
use Laika\Engine\Queue\Driver\JsonFailedJobProvider;
use Laika\Engine\Queue\Driver\RedisDriver;
use Laika\Engine\Queue\Interfaces\FailedJobProviderInterface;
use Laika\Engine\Queue\Interfaces\QueueDriverInterface;

/**
 * Resolves the queue driver / failed-job provider from lf-config/queue.php.
 *
 * The single place that turns 'driver' and 'failed_driver' into objects: the
 * web app (to push), bin/worker (to run) and the queue:* CLI commands (retry,
 * failed, flush) all come through here, so they can never disagree about what
 * a name means.
 *
 * Applications add backends of their own with extend() and extendFailed(),
 * usually from a relay provider's boot(), then name them in lf-config/queue.php.
 */
class Queue
{
    /** @var array<string,callable> Application queue drivers, keyed by lowercased name */
    private static array $drivers = [];

    /** @var array<string,callable> Application failed-job providers, keyed by lowercased name */
    private static array $failedProviders = [];

    ##########################################################################
    /*============================ EXTERNAL API ============================*/
    ##########################################################################

    /**
     * Register an Application-Supplied Queue Driver
     *
     * A name that matches a built-in driver (database, redis, json) replaces it.
     * @param string $name Driver name, as lf-config/queue.php's 'driver' gives it
     * @param callable $resolver Receives the lf-config/queue.php array, returns a QueueDriverInterface
     * @return void
     */
    public static function extend(string $name, callable $resolver): void
    {
        self::$drivers[strtolower($name)] = $resolver;
    }

    /**
     * Register an Application-Supplied Failed-Job Provider
     *
     * A name that matches a built-in provider (database, json) replaces it.
     * @param string $name Provider name, as lf-config/queue.php's 'failed_driver' gives it
     * @param callable $resolver Receives the lf-config/queue.php array, returns a FailedJobProviderInterface
     * @return void
     */
    public static function extendFailed(string $name, callable $resolver): void
    {
        self::$failedProviders[strtolower($name)] = $resolver;
    }

    /**
     * Forget Every Application Driver and Provider. Intended for tests.
     * @return void
     */
    public static function flushExtensions(): void
    {
        self::$drivers = [];
        self::$failedProviders = [];
    }

    /**
     * Build The Configured Queue Driver
     *
     * An unknown name falls back to the database driver, as it always has.
     * @param string $default Driver name used when lf-config/queue.php doesn't set one
     * @return QueueDriverInterface
     * @throws RuntimeException
     */
    public static function driver(string $default = 'json'): QueueDriverInterface
    {
        $driverName = static::driverName($default);

        if (isset(self::$drivers[$driverName])) {
            return static::resolve(self::$drivers[$driverName], $driverName, QueueDriverInterface::class);
        }

        return match ($driverName) {
            'redis' => static::redis(),
            'json'  => new JsonDriver(),
            default => static::database(),
        };
    }

    /**
     * Build The Configured Failed-Job Provider
     *
     * 'failed_driver' defaults to the queue driver's family: 'database' for the
     * database driver, 'json' for everything else, since that needs no setup.
     * @param string $default Queue driver name used when lf-config/queue.php doesn't set one
     * @return FailedJobProviderInterface
     * @throws RuntimeException
     */
    public static function failedProvider(string $default = 'database'): FailedJobProviderInterface
    {
        $driverName = static::driverName($default);
        $failedName = strtolower((string) config(
            'queue',
            'failed_driver',
            $driverName === 'database' ? 'database' : 'json'
        ));

        if (isset(self::$failedProviders[$failedName])) {
            return static::resolve(self::$failedProviders[$failedName], $failedName, FailedJobProviderInterface::class);
        }

        if ($failedName === 'database') {
            // The table is not created here: see FailedJobModelSchema::up()
            $connection = static::connectionName();
            static::connect($connection);
            return new DatabaseFailedJobProvider($connection);
        }

        return new JsonFailedJobProvider();
    }

    ##########################################################################
    /*============================ INTERNAL API ============================*/
    ##########################################################################

    /**
     * Lowercased 'driver' From lf-config/queue.php
     * @param string $default
     * @return string
     */
    protected static function driverName(string $default): string
    {
        return strtolower((string) config('queue', 'driver', $default));
    }

    /**
     * 'connection' From lf-config/queue.php
     * @return string
     */
    protected static function connectionName(): string
    {
        return (string) config('queue', 'connection', 'default');
    }

    /**
     * Redis Driver
     *
     * Connects with lf-config/redis.php as-is, namespaced under its prefix plus
     * ':queue' so queue keys can't collide with cache or session keys. Built with
     * fromConfig(), not a raw client, so a forked worker child reconnects with
     * its own socket instead of sharing the parent's.
     * @return QueueDriverInterface
     * @throws RuntimeException
     */
    protected static function redis(): QueueDriverInterface
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException("queue.driver is 'redis' but the redis extension isn't loaded.");
        }

        $prefix = trim((string) config('redis', 'prefix', 'laika'), ':') . ':queue';

        return RedisDriver::fromConfig([], $prefix);
    }

    /**
     * Database Driver
     *
     * Tables are not created here: see QueueModelSchema::up().
     * @return QueueDriverInterface
     * @throws RuntimeException
     */
    protected static function database(): QueueDriverInterface
    {
        $connection = static::connectionName();
        static::connect($connection);

        return new DatabaseDriver($connection);
    }

    /**
     * Call an Application Resolver and Check What It Returned
     * @param callable $resolver
     * @param string $name
     * @param class-string $contract
     * @return object
     * @throws RuntimeException
     */
    protected static function resolve(callable $resolver, string $name, string $contract): object
    {
        $built = $resolver((array) (config('queue') ?? []));

        if (!$built instanceof $contract) {
            throw new RuntimeException("Queue driver [{$name}] must resolve to {$contract}.");
        }

        return $built;
    }

    /**
     * Register the queue's database connection under its own name
     *
     * An unnamed Connection::add() registers under the default name, so a
     * queue.connection other than 'default' used to overwrite 'default'.
     * @param string $connection
     * @return void
     * @throws RuntimeException
     */
    protected static function connect(string $connection): void
    {
        if (Connection::has($connection)) {
            return;
        }

        $config = config('database', $connection);

        if (!is_array($config) || $config === []) {
            throw new RuntimeException("Database connection [{$connection}] is not defined in lf-config/database.php.");
        }

        Connection::add($config, $connection);
    }
}
