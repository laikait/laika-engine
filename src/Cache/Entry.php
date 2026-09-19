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

/**
 * One cached value plus its expiry, and the serialization around it.
 *
 * The envelope is why a stored null is not a miss. RedisStorage returns null
 * both for "no such key" and for "unserialize failed", so a legitimately
 * stored null, false or 0 reads back as absent and the caller recomputes it
 * forever. Wrapping the value means the presence of the envelope answers
 * "is it cached", and the value inside answers "what is it" -- two different
 * questions that a bare value cannot keep apart.
 *
 * Final on purpose: an immutable value object.
 */
final class Entry
{
    /**
     * @param mixed $value The cached value, whatever it is
     * @param int $expires Unix time, or 0 for never
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $expires = 0
    ) {
    }

    /**
     * @param ?int $now Injectable for tests
     * @return bool
     */
    public function expired(?int $now = null): bool
    {
        return $this->expires !== 0 && ($now ?? time()) >= $this->expires;
    }

    /**
     * Build From a TTL Rather Than an Absolute Time
     * @param mixed $value
     * @param int $ttl Seconds; 0 never expires
     * @return self
     */
    public static function forTtl(mixed $value, int $ttl): self
    {
        return new self($value, $ttl > 0 ? time() + $ttl : 0);
    }

    /**
     * @return string
     */
    public function serialize(): string
    {
        return serialize(['v' => $this->value, 'e' => $this->expires]);
    }

    /**
     * Rebuild an Entry From Stored Bytes
     *
     * allowed_classes defaults to false, so an entry holding an object comes
     * back as __PHP_Incomplete_Class rather than being instantiated. A cache
     * is shared, writable state -- on Redis it may be writable by anything
     * else on that server -- and unserializing arbitrary classes out of it is
     * an object-injection gadget chain. Opt in per application instead.
     *
     * @param string $raw
     * @param bool|string[] $allowedClasses
     * @return ?self Null when the bytes are not an entry this wrote
     */
    public static function unserialize(string $raw, bool|array $allowedClasses = false): ?self
    {
        if ($raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => $allowedClasses]);

        // Not an envelope: either corrupt, or written by something that is not
        // this cache. Either way it is not ours to interpret -- treat as a miss.
        if (!is_array($data) || !array_key_exists('v', $data) || !array_key_exists('e', $data)) {
            return null;
        }

        return new self($data['v'], (int) $data['e']);
    }
}
