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

/**
 * The cache that lives and dies with the process.
 *
 * This is the honest home for the static memos scattered through the
 * framework today: same lifetime, but with a TTL, a reset point and one place
 * to look. Under FPM that is one request. Under the queue worker on a host
 * without pcntl it is the whole life of the worker, which is exactly why
 * flush() has to be reachable from outside.
 *
 * Nothing is serialized, so a cached object is the caller's own instance and
 * can still be mutated after it was stored.
 */
class ArrayDriver implements CacheDriverInterface
{
    /** @var array<string,Entry> */
    protected array $entries = [];

    /**
     * @param int $defaultTtl Seconds; 0 never expires
     */
    public function __construct(protected int $defaultTtl = 0)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->live($key);

        return $entry === null ? $default : $entry->value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->entries[$key] = Entry::forTtl($value, $ttl ?? $this->defaultTtl);

        return true;
    }

    public function has(string $key): bool
    {
        // Presence, not truthiness: a stored null or false is still cached
        return $this->live($key) !== null;
    }

    public function pop(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->entries = [];

        return true;
    }

    public function increment(string $key, int $by = 1): int|false
    {
        $entry = $this->live($key);
        $current = $entry?->value ?? 0;

        if (!is_int($current) && !(is_string($current) && ctype_digit(ltrim($current, '-')))) {
            return false;
        }

        $next = (int) $current + $by;

        // Expiry carried over, never renewed: a counter that renewed its own TTL
        // on every increment would never expire under steady traffic, which is
        // the one thing a rate limiter built on it must not do.
        $expires = $entry?->expires ?? ($this->defaultTtl > 0 ? time() + $this->defaultTtl : 0);
        $this->entries[$key] = new Entry($next, $expires);

        return $next;
    }

    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->increment($key, -$by);
    }

    /**
     * @param string $key
     * @return ?Entry Null when absent or expired
     */
    protected function live(string $key): ?Entry
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && $entry->expired()) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry;
    }
}
