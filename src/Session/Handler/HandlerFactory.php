<?php
/**
 * Laika Session
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Session\Handler;

use Laika\Engine\Model\Model;
use Laika\Engine\Session\SessionConfig;
use Laika\Engine\Session\Contracts\SessionDriverInterface;
use Laika\Engine\Session\Exceptions\SessionHandlerException;

/**
 * Builds the session driver named by SessionConfig.
 *
 * Applications add drivers of their own with extend(), usually from a relay
 * provider's boot(), and select one with SessionConfig::custom().
 */
class HandlerFactory
{
    /** @var array<string,callable> Application drivers, keyed by lowercased name */
    private static array $resolvers = [];

    /**
     * Register an Application-Supplied Driver
     *
     * A name that matches a built-in driver replaces it.
     * @param string $name Driver name
     * @param callable $resolver Receives the params array, returns a SessionDriverInterface
     * @return void
     */
    public static function extend(string $name, callable $resolver): void
    {
        self::$resolvers[strtolower($name)] = $resolver;
    }

    /**
     * Check a Driver Can Be Built
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        $name = strtolower($name);

        return isset(self::$resolvers[$name]) || in_array($name, SessionConfig::DRIVERS, true);
    }

    /**
     * Every Driver Name That Can Be Built, Built-In First
     * @return string[]
     */
    public static function drivers(): array
    {
        return array_values(array_unique(array_merge(SessionConfig::DRIVERS, array_keys(self::$resolvers))));
    }

    /**
     * Forget Every Application Driver. Intended for tests.
     * @return void
     */
    public static function flushExtensions(): void
    {
        self::$resolvers = [];
    }

    /**
     * @param string $driver One of drivers()
     * @param array<string,mixed> $params Driver params
     * @return SessionDriverInterface
     */
    public static function make(string $driver, array $params = []): SessionDriverInterface
    {
        $resolver = self::$resolvers[strtolower($driver)] ?? null;

        if ($resolver !== null) {
            $handler = $resolver($params);

            if (!$handler instanceof SessionDriverInterface) {
                throw new SessionHandlerException(
                    "Session driver [{$driver}] must resolve to " . SessionDriverInterface::class . '.'
                );
            }

            return $handler;
        }

        return match ($driver) {
            SessionConfig::DRIVER_FILE      => new FileHandler($params),
            SessionConfig::DRIVER_REDIS     => new RedisHandler($params),
            SessionConfig::DRIVER_MEMCACHED => new MemcachedHandler($params),
            SessionConfig::DRIVER_MYSQL     => new MySQLHandler($params),
            SessionConfig::DRIVER_MODEL     => static::model($params),
            default                         => throw new SessionHandlerException(
                "Unknown session driver [{$driver}]. Expected one of: " . implode(', ', static::drivers()) . '.'
            ),
        };
    }

    /**
     * The model driver is the one that can be unavailable at runtime, because
     * laika-model is an optional dependency. Fail with the package name rather
     * than a bare "class not found".
     *
     * @param array<string,mixed> $params
     * @return SessionDriverInterface
     */
    protected static function model(array $params): SessionDriverInterface
    {
        if (!class_exists(Model::class)) {
            throw new SessionHandlerException(
                'The [model] session driver needs laikait/laika-model. Install it, or use SessionConfig::mysql() '
                . 'which talks to PDO directly.'
            );
        }

        return new ModelHandler($params);
    }
}
