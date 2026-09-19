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

namespace Laika\Engine\Cache\Driver;

use Laika\Engine\Cache\Contracts\CacheDriverInterface;
use Laika\Engine\Cache\Entry;
use Redis;
use Throwable;

/**
 * Redis-backed cache.
 *
 * Takes an already-connected client rather than building one, so the package
 * needs no knowledge of how the host framework configures Redis. Inside a
 * Laika app the factory hands it laika-core's RedisConnection::make(), which
 * already handles the extension check, ACL auth, database select and timeouts.
 *
 * Keys are namespaced "<prefix>:cache" so they cannot collide with the queue,
 * which claims "<prefix>:queue" the same way.
 *
 * A Redis that goes away mid-request degrades to a miss rather than throwing.
 * A cache is an optimisation; taking the request down because the optimisation
 * is unavailable turns a slow page into a broken one.
 */
class RedisDriver implements CacheDriverInterface
{
    protected string $prefix;

    /**
     * @param Redis $client Already connected
     * @param string $prefix Namespace, without a trailing colon
     * @param int $defaultTtl Seconds; 0 never expires
     * @param bool|string[] $allowedClasses Passed to unserialize()
     */
    public function __construct(
        protected Redis $client,
        string $prefix = 'laika',
        protected int $defaultTtl = 0,
        protected bool|array $allowedClasses = false
    ) {
        $this->prefix = trim($prefix, ':') . ':cache:';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $raw = $this->client->get($this->prefix . $key);
        } catch (Throwable) {
            return $default;
        }

        if (!is_string($raw)) {
            return $default;
        }

        $entry = Entry::unserialize($raw, $this->allowedClasses);

        return ($entry === null || $entry->expired()) ? $default : $entry->value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;
        $payload = Entry::forTtl($value, $ttl)->serialize();

        try {
            return (bool) ($ttl > 0
                ? $this->client->setex($this->prefix . $key, $ttl, $payload)
                : $this->client->set($this->prefix . $key, $payload));
        } catch (Throwable) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            // exists(), not get() !== null: a stored null is still cached
            return (bool) $this->client->exists($this->prefix . $key);
        } catch (Throwable) {
            return false;
        }
    }

    public function pop(string $key): bool
    {
        try {
            $this->client->del($this->prefix . $key);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function flush(): bool
    {
        // Scanned and deleted by prefix, never flushDB(): the database may hold
        // this application's queue and sessions, and anything else sharing the
        // server. Clearing the cache must not take those with it.
        try {
            $iterator = null;

            do {
                $keys = $this->client->scan($iterator, $this->prefix . '*', 500);

                if (is_array($keys) && $keys !== []) {
                    $this->client->del($keys);
                }
            } while ($iterator > 0);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function increment(string $key, int $by = 1): int|false
    {
        // Not Redis's own INCR: that operates on a bare integer, while every
        // entry here is a serialized envelope. This read-change-write is not
        // atomic across processes, so a strict cross-process counter should
        // use the file driver, whose increment holds a lock.
        $current = $this->get($key, 0);

        if (!is_int($current) && !(is_string($current) && ctype_digit(ltrim($current, '-')))) {
            return false;
        }

        $next = (int) $current + $by;

        try {
            // Whatever TTL the key already had is kept
            $ttl = $this->client->ttl($this->prefix . $key);
        } catch (Throwable) {
            $ttl = -1;
        }

        return $this->set($key, $next, is_int($ttl) && $ttl > 0 ? $ttl : 0) ? $next : false;
    }

    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->increment($key, -$by);
    }
}
