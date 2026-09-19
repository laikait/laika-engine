<?php
/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Model\Drivers;

use Laika\Engine\Model\Exceptions\DriverException;

class DriverFactory
{
    /** Canonical alias map → driver class */
    private static array $map = [
        'mysql'    => MySqlDriver::class,
        'mariadb'  => MySqlDriver::class,   // MariaDB is MySQL-compatible
        'pgsql'    => PgSqlDriver::class,
        'postgres' => PgSqlDriver::class,
        'sqlsrv'   => SqlSrvDriver::class,
        'oci'      => OciDriver::class,
        'oracle'   => OciDriver::class,
        'firebird' => FirebirdDriver::class,
        'ibase'    => FirebirdDriver::class,
        'sqlite'   => SqliteDriver::class,
        'sqlite3'  => SqliteDriver::class,
    ];

    /** Custom drivers registered by the user */
    private static array $custom = [];

    /** @var array<string,callable> Custom drivers registered with a resolver */
    private static array $resolvers = [];

    /**
     * Register a custom driver.
     *
     * @param string $alias   e.g. "mydb"
     * @param class-string<DriverInterface> $class
     */
    public static function register(string $alias, string $class): void
    {
        if (!is_a($class, DriverInterface::class, true)) {
            throw new DriverException("Driver class [{$class}] must implement DriverInterface.");
        }
        self::$custom[strtolower($alias)] = $class;
    }

    /**
     * Register a custom driver built by a resolver.
     *
     * The resolver form of register(), for a driver that needs constructor
     * arguments. Checked before register()'d classes and the built-in aliases,
     * so it can also replace a built-in driver.
     *
     * @param string   $alias    e.g. "mydb"
     * @param callable $resolver Receives the connection config array, returns a DriverInterface
     */
    public static function extend(string $alias, callable $resolver): void
    {
        self::$resolvers[strtolower($alias)] = $resolver;
    }

    /**
     * Drop a custom driver registration.
     *
     * Built-in aliases are never removed — this only undoes register(). Without
     * it a registration made in one test leaks into every test that follows,
     * since the registry is static.
     *
     * @return bool Whether anything was actually removed.
     */
    public static function unregister(string $alias): bool
    {
        $alias = strtolower($alias);

        if (!isset(self::$custom[$alias]) && !isset(self::$resolvers[$alias])) {
            return false;
        }

        unset(self::$custom[$alias], self::$resolvers[$alias]);

        return true;
    }

    /**
     * Resolve a driver instance from a config array.
     */
    public static function make(array $config): DriverInterface
    {
        $driver = strtolower($config['driver'] ?? '');

        if (isset(self::$resolvers[$driver])) {
            $instance = (self::$resolvers[$driver])($config);

            if (!$instance instanceof DriverInterface) {
                throw new DriverException("Driver [{$driver}] must resolve to DriverInterface.");
            }

            return $instance;
        }

        if (isset(self::$custom[$driver])) {
            return new self::$custom[$driver]();
        }

        if (isset(self::$map[$driver])) {
            return new self::$map[$driver]();
        }

        throw new DriverException(
            "Unsupported driver [{$driver}]. Supported: " . implode(', ', static::supported())
        );
    }

    /** Return all known driver aliases. */
    public static function supported(): array
    {
        return array_keys(array_merge(self::$map, self::$custom, self::$resolvers));
    }
}
