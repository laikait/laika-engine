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


namespace Laika\Engine\Model\Concerns;

use Laika\Engine\Model\Connection;

/**
 * Query result caching with remember(), and invalidating it when a table is written.
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait CachesQueries
{
    /**
     * Cache This Query's Result
     *
     * Opt-in per query, and a no-op unless a store was configured through
     * setQueryCache() -- the query then simply runs. get() and count() honour it;
     * first(), find() and pluck() go through get(). cursor() never caches: it
     * exists for result sets too large to hold, which is also too large to cache.
     *
     * A write through this model to the table, or to any table joined into the
     * query, invalidates it. A write the model cannot see -- execute() with raw
     * SQL, another application, a table reached only through a raw expression
     * or subquery -- does not; call forgetQueryCache() for those.
     *
     * @param ?int $ttl Seconds; null uses the configured default
     * @return static
     */
    public function remember(?int $ttl = null): static
    {
        $this->remember = true;
        $this->rememberTtl = $ttl;

        return $this;
    }

    /**
     * Configure Where Remembered Queries Are Cached
     *
     * Takes a resolver so nothing is built or connected at boot, only when a
     * query first asks. It must return an object with get(), set() and pop() --
     * a Laika\Engine\Cache\Contracts\CacheDriverInterface -- or null for no cache.
     * This package does not require laika-cache, so the type is not declared.
     *
     * @param ?callable $resolver Null disables query caching
     * @param int $ttl Default seconds for remember() without its own TTL
     * @return void
     */
    public static function setQueryCache(?callable $resolver, int $ttl = 60): void
    {
        self::$queryCache = $resolver;
        self::$queryCacheTtl = max(0, $ttl);
    }

    /**
     * Invalidate Every Cached Query on a Table
     *
     * For writes the model does not see. Takes effect after the current
     * transaction commits, like every other invalidation.
     *
     * @param string $table Unquoted table name
     * @param ?string $connection Default is 'default'
     * @return void
     */
    public static function forgetQueryCache(string $table, ?string $connection = null): void
    {
        $connection ??= 'default';
        $key = static::generationKey($connection, $table);

        Connection::afterCommit(static function () use ($key): void {
            static::cacheStore()?->pop($key);
        }, $connection);
    }

    /**
     * Cache Key For The Current Query, or Null When it Must Not be Cached
     *
     * Null inside a transaction: a read there can see rows that are not
     * committed yet, and caching those would hand them to every other request.
     *
     * @param string $kind get or count, so the two never share an entry
     * @param string $sql The built statement
     * @return ?string
     */
    protected function queryCacheKey(string $kind, string $sql): ?string
    {
        if (!$this->remember || self::$queryCache === null) {
            return null;
        }

        if (Connection::transactionLevel($this->connection) > 0) {
            return null;
        }

        $store = static::cacheStore();

        if ($store === null) {
            return null;
        }

        $generations = [];

        foreach (array_unique(array_merge([$this->table], $this->joinTables)) as $table) {
            $generations[$table] = $this->generation($store, $table);
        }

        try {
            $fingerprint = serialize([$kind, $this->connection, $this->driver(), $sql, $this->bindings, $generations]);
        } catch (\Throwable) {
            // A binding that cannot be serialized cannot be part of a key
            return null;
        }

        return 'query:' . sha1($fingerprint);
    }

    /**
     * @param string $key
     * @return mixed The cached value, or the miss sentinel
     */
    protected function queryCacheRead(string $key): mixed
    {
        self::$miss ??= new \stdClass();

        try {
            return static::cacheStore()?->get($key, self::$miss) ?? self::$miss;
        } catch (\Throwable) {
            // A cache that fails is a miss, never a failed query
            return self::$miss;
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function queryCacheWrite(string $key, mixed $value): void
    {
        try {
            static::cacheStore()?->set($key, $value, $this->rememberTtl ?? self::$queryCacheTtl);
        } catch (\Throwable) {
            // Not caching is always a safe outcome
        }
    }

    /**
     * Invalidate a Table's Cached Queries Once The Write is Committed
     * @param string $table
     * @return void
     */
    protected function invalidateQueryCache(string $table): void
    {
        // Nothing configured, nothing cached, nothing to do: writes stay free
        if (self::$queryCache === null) {
            return;
        }

        static::forgetQueryCache($table, $this->connection);
    }

    /**
     * A Table's Current Generation Token
     *
     * Invalidation deletes the token rather than counting it up. A counter that
     * expired, or that Memcached evicted, would restart and could line up with a
     * key some old entry was stored under. A token minted fresh whenever it is
     * missing never does: losing it can only cause a miss.
     *
     * @param object $store
     * @param string $table
     * @return string
     */
    protected function generation(object $store, string $table): string
    {
        $key = static::generationKey($this->connection, $table);

        try {
            $token = $store->get($key);

            if (is_string($token) && $token !== '') {
                return $token;
            }

            $token = bin2hex(random_bytes(8));
            // No expiry: the token must outlive every entry keyed on it
            $store->set($key, $token, 0);

            return $token;
        } catch (\Throwable) {
            // Unique per call, so a broken store yields a miss, never a stale hit
            return bin2hex(random_bytes(8));
        }
    }

    /**
     * @param string $connection
     * @param string $table
     * @return string
     */
    protected static function generationKey(string $connection, string $table): string
    {
        return 'query-gen:' . $connection . ':' . strtolower(trim($table));
    }

    /**
     * @return ?object
     */
    protected static function cacheStore(): ?object
    {
        if (self::$queryCache === null) {
            return null;
        }

        try {
            $store = (self::$queryCache)();
        } catch (\Throwable) {
            return null;
        }

        return is_object($store) ? $store : null;
    }
}
