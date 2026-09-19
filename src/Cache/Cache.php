<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Cache;

use Laika\Engine\Cache\Contracts\CacheDriverInterface;
use Laika\Engine\Cache\Driver\ArrayDriver;
use Laika\Engine\Cache\Driver\FileDriver;
use Laika\Engine\Cache\Driver\MemcachedDriver;
use Laika\Engine\Cache\Driver\RedisDriver;
use Laika\Engine\Cache\Exceptions\CacheException;
use Throwable;
use Laika\Engine\Core\Support\Macroable;

/**
 * The cache the application talks to.
 *
 * Holds the configuration, builds drivers on first use, and adds the parts
 * that are the same whatever the backend -- remember(), forever(), pull().
 *
 * It takes its configuration as an array rather than calling config(). That
 * keeps the package free of the framework: config() lives in laika-core, which
 * this package cannot require without a cycle, and the relay is the seam that
 * reads it and passes it in.
 */
class Cache
{
    use Macroable;

    /** @var array<string,CacheDriverInterface> Built on first use, by driver name */
    protected array $drivers = [];

    /** @var array<string,callable> Extra drivers registered by the application */
    protected array $resolvers = [];

    /**
     * @param array $config Shaped like lf-config/cache.php
     */
    public function __construct(protected array $config = [])
    {
    }

    /*============================== EXTERNAL API ==============================*/

    /**
     * @param string $key
     * @param mixed $default Returned only on a miss
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver()->get($key, $default);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param ?int $ttl Seconds; null uses the configured default, 0 never expires
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->driver()->set($key, $value, $ttl);
    }

    /**
     * @param string $key
     * @return bool True when present, whatever the value
     */
    public function has(string $key): bool
    {
        return $this->driver()->has($key);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function pop(string $key): bool
    {
        return $this->driver()->pop($key);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->driver()->flush();
    }

    /**
     * @param string $key
     * @param int $by
     * @return int|false
     */
    public function increment(string $key, int $by = 1): int|false
    {
        return $this->driver()->increment($key, $by);
    }

    /**
     * @param string $key
     * @param int $by
     * @return int|false
     */
    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->driver()->decrement($key, $by);
    }

    /**
     * Store Without an Expiry
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->driver()->set($key, $value, 0);
    }

    /**
     * Read and Remove in One Call
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->driver()->get($key, $default);
        $this->driver()->pop($key);

        return $value;
    }

    /**
     * Return The Cached Value, Computing And Storing it on a Miss
     *
     * has() decides, not get() !== null, so a callback that legitimately
     * returns null runs once rather than on every call -- the bug the option()
     * helper has today.
     *
     * @param string $key
     * @param ?int $ttl Seconds; null uses the configured default
     * @param callable $callback Run only on a miss
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $driver = $this->driver();

        if ($driver->has($key)) {
            return $driver->get($key);
        }

        $value = $callback();
        $driver->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Reach a Driver Other Than The Configured One
     * @param string $name
     * @return CacheDriverInterface
     */
    public function store(string $name): CacheDriverInterface
    {
        return $this->driver($name);
    }

    /**
     * Register an Application-Supplied Driver
     * @param string $name
     * @param callable $resolver Receives the config array, returns a CacheDriverInterface
     * @return void
     */
    public function extend(string $name, callable $resolver): void
    {
        $name = strtolower($name);
        $this->resolvers[$name] = $resolver;
        unset($this->drivers[$name]);
    }

    /**
     * Drop Everything Held For The Life of The Process
     *
     * Not a flush: the shared backends keep their entries. This clears only
     * what a process would otherwise carry between requests -- the array
     * driver's entries and the built driver instances.
     *
     * Under FPM a process is one request and this never needs calling. Under a
     * worker that serves many requests it does, or the second request reads
     * the first one's state.
     *
     * @return void
     */
    public function resetProcess(): void
    {
        foreach ($this->drivers as $driver) {
            if ($driver instanceof ArrayDriver) {
                $driver->flush();
            }
        }

        $this->drivers = [];
    }

    /**
     * @return array The configuration as given
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Build or Return a Driver
     * @param ?string $name Null uses the configured driver
     * @return CacheDriverInterface
     */
    public function driver(?string $name = null): CacheDriverInterface
    {
        $name = strtolower($name ?? (string) ($this->config['driver'] ?? 'file'));

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /*============================== INTERNAL API ==============================*/

    /**
     * @param string $name
     * @return CacheDriverInterface
     */
    protected function resolve(string $name): CacheDriverInterface
    {
        if (isset($this->resolvers[$name])) {
            return ($this->resolvers[$name])($this->config);
        }

        return match ($name) {
            'array' => new ArrayDriver($this->ttl()),
            'file' => new FileDriver($this->path(), $this->ttl(), $this->allowedClasses()),
            'redis' => $this->redis(),
            'memcached' => $this->memcached(),
            default => throw new CacheException(
                "Unknown cache driver: [{$name}]. Use array, file, redis or memcached, or register it with Cache::extend()."
            ),
        };
    }

    /**
     * @return CacheDriverInterface
     */
    protected function redis(): CacheDriverInterface
    {
        if (!extension_loaded('redis')) {
            throw new CacheException("cache.driver is 'redis' but the redis extension is not loaded.");
        }

        return new RedisDriver($this->redisClient(), $this->prefix(), $this->ttl(), $this->allowedClasses());
    }

    /**
     * @return CacheDriverInterface
     */
    protected function memcached(): CacheDriverInterface
    {
        if (!extension_loaded('memcached')) {
            throw new CacheException("cache.driver is 'memcached' but the memcached extension is not loaded.");
        }

        return new MemcachedDriver($this->memcachedClient(), $this->prefix(), $this->ttl(), $this->allowedClasses());
    }

    /**
     * Connected Redis Client
     *
     * laika-core already knows how to build one, including ACL auth, the
     * database select and timeouts, but this package cannot require it without
     * a cycle. So it is used when present and reimplemented minimally when not
     * -- the "assumed present, never guaranteed" shape laika-shield uses for
     * the relay contract.
     *
     * @return \Redis
     */
    protected function redisClient(): \Redis
    {
        $settings = $this->connectionConfig('redis');
        $connection = 'Laika\\Engine\\Core\\Storage\\Connection\\RedisConnection';

        if (class_exists($connection)) {
            return $connection::make($settings);
        }

        $client = new \Redis();

        try {
            $connected = $client->connect(
                (string) ($settings['host'] ?? '127.0.0.1'),
                (int) ($settings['port'] ?? 6379),
                (float) ($settings['timeout'] ?? 2.5)
            );
        } catch (Throwable $e) {
            throw new CacheException('Unable to connect to Redis for the cache.', 500, $e);
        }

        if ($connected === false) {
            throw new CacheException('Unable to connect to Redis for the cache.');
        }

        if (!empty($settings['password'])) {
            $client->auth(
                empty($settings['username'])
                    ? (string) $settings['password']
                    : ['user' => (string) $settings['username'], 'pass' => (string) $settings['password']]
            );
        }

        if (!empty($settings['database'])) {
            $client->select((int) $settings['database']);
        }

        return $client;
    }

    /**
     * @return \Memcached
     */
    protected function memcachedClient(): \Memcached
    {
        $settings = $this->connectionConfig('memcached');
        $connection = 'Laika\\Engine\\Core\\Storage\\Connection\\MemcachedConnection';

        if (class_exists($connection)) {
            return $connection::make($settings);
        }

        $client = new \Memcached();
        $client->addServer((string) ($settings['host'] ?? '127.0.0.1'), (int) ($settings['port'] ?? 11211));

        if (!empty($settings['username'])) {
            $client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $client->setSaslAuthData((string) $settings['username'], (string) ($settings['password'] ?? ''));
        }

        return $client;
    }

    /**
     * @param string $name
     * @return array
     */
    protected function connectionConfig(string $name): array
    {
        return (array) ($this->config['connections'][$name] ?? []);
    }

    /**
     * @return int Default TTL in seconds
     */
    protected function ttl(): int
    {
        return max(0, (int) ($this->config['ttl'] ?? 0));
    }

    /**
     * @return string
     */
    protected function prefix(): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? 'laika'));

        return $prefix === '' ? 'laika' : $prefix;
    }

    /**
     * @return string Directory for the file driver
     */
    protected function path(): string
    {
        $path = $this->config['path'] ?? null;

        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        // Its own directory, never lf-storage/cache itself: the resource
        // manifest and the compiled Twig templates live there, and a flush()
        // that globbed the parent would take both with it.
        $base = defined('APP_PATH') ? constant('APP_PATH') . '/lf-storage' : sys_get_temp_dir();

        return $base . '/cache/data';
    }

    /**
     * @return bool|string[] What unserialize() may instantiate
     */
    protected function allowedClasses(): bool|array
    {
        $allowed = $this->config['serialize']['allowed_classes'] ?? false;

        return is_array($allowed) ? array_values(array_map('strval', $allowed)) : (bool) $allowed;
    }
}
