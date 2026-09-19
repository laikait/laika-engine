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

namespace Laika\Engine\Cache\Contracts;

/**
 * What every cache backend must answer.
 *
 * pop() rather than delete(), flush() rather than clear(): RedisStorage,
 * JsonStorage, Config and Option all already use that pair, so a cache that
 * spelled it differently would be the odd one out in its own framework.
 *
 * Two rules the implementations must hold that the signatures cannot express:
 *
 * 1. A miss is not null. get() returns $default only when the key is absent or
 *    expired; a stored null, false or 0 comes back as itself.
 * 2. has() is not get() !== null. It answers whether the key is present,
 *    whatever its value.
 */
interface CacheDriverInterface
{
    /**
     * @param string $key
     * @param mixed $default Returned only on a miss
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param string $key
     * @param mixed $value
     * @param ?int $ttl Seconds; null uses the configured default, 0 never expires
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * @param string $key
     * @return bool True when the key is present and unexpired, whatever its value
     */
    public function has(string $key): bool;

    /**
     * @param string $key
     * @return bool True when the key is gone afterwards, including when it was already absent
     */
    public function pop(string $key): bool;

    /**
     * Drop every entry this driver owns.
     * @return bool
     */
    public function flush(): bool;

    /**
     * @param string $key Treated as 0 when absent
     * @param int $by
     * @return int|false The new value, or false when the entry holds a non-integer
     */
    public function increment(string $key, int $by = 1): int|false;

    /**
     * @param string $key Treated as 0 when absent
     * @param int $by
     * @return int|false The new value, or false when the entry holds a non-integer
     */
    public function decrement(string $key, int $by = 1): int|false;
}
