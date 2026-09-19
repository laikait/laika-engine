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
use Laika\Engine\Cache\Exceptions\CacheException;

/**
 * One file per entry, under lf-storage/cache/data.
 *
 * The default driver because it is the only one needing no extension and no
 * server. Two things it has to get right that a naive implementation does not:
 *
 * A reader must never see a half-written file, so a write goes to a temp file
 * in the same directory and is moved into place with rename(), which is atomic
 * within a filesystem. Writing in place would let a concurrent reader
 * unserialize a truncated entry.
 *
 * Nothing else reclaims expired files. A key written once and never read again
 * leaves its file behind forever, so a small share of writes sweep the
 * directory. Without that the cache directory is an unbounded disk leak.
 */
class FileDriver implements CacheDriverInterface
{
    /** @var int One write in this many sweeps expired files */
    private const GC_DIVISOR = 100;

    /** @var string Extension for an entry file */
    private const EXTENSION = '.cache';

    protected string $path;

    /**
     * @param string $path Directory the entries live in
     * @param int $defaultTtl Seconds; 0 never expires
     * @param bool|string[] $allowedClasses Passed to unserialize()
     */
    public function __construct(
        string $path,
        protected int $defaultTtl = 0,
        protected bool|array $allowedClasses = false
    ) {
        $this->path = rtrim(str_replace('\\', '/', $path), '/');

        if (!is_dir($this->path) && !@mkdir($this->path, 0o755, true) && !is_dir($this->path)) {
            // The second is_dir() is not redundant: a parallel process may have
            // created the directory between the check and the mkdir.
            throw new CacheException("Unable to create cache directory: [{$this->path}].");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->read($key);

        return $entry === null ? $default : $entry->value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->write($key, Entry::forTtl($value, $ttl ?? $this->defaultTtl));
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function pop(string $key): bool
    {
        $file = $this->file($key);

        // Already absent counts as removed
        return !is_file($file) || @unlink($file);
    }

    public function flush(): bool
    {
        $files = @glob($this->path . '/*' . self::EXTENSION);

        if ($files === false) {
            return false;
        }

        $ok = true;

        foreach ($files as $file) {
            $ok = (@unlink($file) || !is_file($file)) && $ok;
        }

        return $ok;
    }

    public function increment(string $key, int $by = 1): int|false
    {
        // Read and write under one exclusive lock. Doing it as get() then set()
        // lets two processes read the same value and both write n+1, losing an
        // increment -- the hazard JsonStorage documents on its own
        // read-modify-write. FileDriverConcurrencyTest proves the difference.
        return $this->mutate($key, function (?Entry $entry) use ($by): array {
            $current = $entry?->value ?? 0;

            if (!$this->numeric($current)) {
                return [null, false];
            }

            $next = (int) $current + $by;

            // Expiry carried over, never renewed
            return [new Entry($next, $entry?->expires ?? $this->defaultExpiry()), $next];
        });
    }

    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->increment($key, -$by);
    }

    /**
     * Sweep Expired Entries
     *
     * Public so a scheduled command can call it directly rather than waiting
     * for the lottery.
     *
     * @return int Files removed
     */
    public function gc(): int
    {
        $files = @glob($this->path . '/*' . self::EXTENSION);

        if ($files === false) {
            return 0;
        }

        $removed = 0;
        $now = time();

        foreach ($files as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            $entry = Entry::unserialize($raw, $this->allowedClasses);

            // Unreadable entries go too: they can never be served
            if ($entry === null || $entry->expired($now)) {
                $removed += (int) @unlink($file);
            }
        }

        return $removed;
    }

    /*=============================== INTERNAL ===============================*/

    /**
     * @param string $key
     * @return ?Entry Null for absent, unreadable or expired
     */
    protected function read(string $key): ?Entry
    {
        $file = $this->file($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $entry = Entry::unserialize($raw, $this->allowedClasses);

        if ($entry === null) {
            return null;
        }

        if ($entry->expired()) {
            @unlink($file);
            return null;
        }

        return $entry;
    }

    /**
     * @param string $key
     * @param Entry $entry
     * @return bool
     */
    protected function write(string $key, Entry $entry): bool
    {
        $this->maybeCollect();

        $file = $this->file($key);

        // Same directory, so the rename below stays on one filesystem and is
        // therefore atomic. A temp file elsewhere would degrade to copy+delete.
        $temp = @tempnam($this->path, 'tmp');

        if ($temp === false) {
            return false;
        }

        if (@file_put_contents($temp, $entry->serialize(), LOCK_EX) === false) {
            @unlink($temp);
            return false;
        }

        // Windows rename() will not clobber an existing file
        if (PHP_OS_FAMILY === 'Windows' && is_file($file)) {
            @unlink($file);
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);
            return false;
        }

        @chmod($file, 0o644);

        return true;
    }

    /**
     * Read, Change and Write Under One Exclusive Lock
     *
     * The sequence is JsonStorage::mutate()'s: "c+" so the handle is created
     * without truncating, LOCK_EX held across the read and the write, then
     * ftruncate/rewind/fwrite, and the unlock in a finally so a throw inside
     * the callback cannot strand the lock.
     *
     * @param string $key
     * @param callable $fn Receives ?Entry, returns [entry to store or null, value to return]
     * @return mixed
     */
    protected function mutate(string $key, callable $fn): mixed
    {
        $handle = @fopen($this->file($key), 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            $entry = is_string($raw) ? Entry::unserialize($raw, $this->allowedClasses) : null;

            if ($entry !== null && $entry->expired()) {
                $entry = null;
            }

            [$next, $result] = $fn($entry);

            if ($next === null) {
                return $result;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $next->serialize());
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param string $key
     * @return string
     */
    protected function file(string $key): string
    {
        // Hashed: a key may hold slashes, colons or anything else a caller put
        // in it, none of which can be trusted in a path.
        return $this->path . '/' . sha1($key) . self::EXTENSION;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected function numeric(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-')));
    }

    /**
     * @return int
     */
    protected function defaultExpiry(): int
    {
        return $this->defaultTtl > 0 ? time() + $this->defaultTtl : 0;
    }

    /**
     * @return void
     */
    protected function maybeCollect(): void
    {
        if (random_int(1, self::GC_DIVISOR) === 1) {
            $this->gc();
        }
    }
}
