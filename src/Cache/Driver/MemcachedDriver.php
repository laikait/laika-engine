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
use Memcached;
use Throwable;

/**
 * Memcached-backed cache.
 *
 * Takes an already-connected client, as RedisDriver does.
 *
 * Worth knowing about this backend, and documented on laika-core's
 * MemcachedStorage too: addServer() does not connect, so a dead or misspelled
 * server is never reported. It surfaces only as false from set() and a miss
 * from get(), forever -- indistinguishable from a cold cache.
 *
 * Keys are hashed. Memcached caps a key at 250 bytes including the prefix and
 * rejects spaces and control characters, and a key assembled from a SQL
 * string or a URL will breach both.
 */
class MemcachedDriver implements CacheDriverInterface
{
    /** @var int Memcached reads a TTL above this as a Unix timestamp, not a duration */
    private const RELATIVE_TTL_LIMIT = 2592000;

    protected string $prefix;

    /**
     * @param Memcached $client Already configured with its servers
     * @param string $prefix Namespace, without a trailing colon
     * @param int $defaultTtl Seconds; 0 never expires
     * @param bool|string[] $allowedClasses Passed to unserialize()
     */
    public function __construct(
        protected Memcached $client,
        string $prefix = 'laika',
        protected int $defaultTtl = 0,
        protected bool|array $allowedClasses = false
    ) {
        $this->prefix = trim($prefix, ':') . ':cache:';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entry($key);

        return $entry === null ? $default : $entry->value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;

        try {
            return $this->client->set($this->id($key), Entry::forTtl($value, $ttl)->serialize(), $this->expiry($ttl));
        } catch (Throwable) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        // The envelope's presence is the answer, not its value
        return $this->entry($key) !== null;
    }

    public function pop(string $key): bool
    {
        try {
            $this->client->delete($this->id($key));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function flush(): bool
    {
        // Memcached has no prefix scan, so this is all or nothing: the whole
        // server is cleared, including anything else using it.
        try {
            return $this->client->flush();
        } catch (Throwable) {
            return false;
        }
    }

    public function increment(string $key, int $by = 1): int|false
    {
        // Not the native increment(): the stored value is a serialized
        // envelope, not a bare integer. Not atomic across processes.
        $current = $this->get($key, 0);

        if (!is_int($current) && !(is_string($current) && ctype_digit(ltrim($current, '-')))) {
            return false;
        }

        $next = (int) $current + $by;

        return $this->set($key, $next) ? $next : false;
    }

    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->increment($key, -$by);
    }

    /*=============================== INTERNAL ===============================*/

    /**
     * @param string $key
     * @return ?Entry
     */
    protected function entry(string $key): ?Entry
    {
        try {
            $raw = $this->client->get($this->id($key));
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw)) {
            return null;
        }

        $entry = Entry::unserialize($raw, $this->allowedClasses);

        return ($entry === null || $entry->expired()) ? null : $entry;
    }

    /**
     * @param string $key
     * @return string Prefixed, hashed, always within the 250-byte cap
     */
    protected function id(string $key): string
    {
        return $this->prefix . sha1($key);
    }

    /**
     * @param int $ttl Seconds
     * @return int What Memcached should be handed
     */
    protected function expiry(int $ttl): int
    {
        if ($ttl <= 0) {
            return 0;
        }

        // Above 30 days Memcached reads the value as an absolute Unix time, so
        // a raw 60-day TTL would land in 1970 and expire at once.
        return $ttl > self::RELATIVE_TTL_LIMIT ? time() + $ttl : $ttl;
    }
}
