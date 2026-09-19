# laika-cache

The cache for the [Laika PHP MVC Framework](https://github.com/laikait/laika-framework): one API over four drivers — file, array, Redis and Memcached.

## Install

```bash
composer require laikait/laika-cache
```

laika-core requires it, so a Laika app already has it. The package itself needs only PHP 8.1+; `ext-redis` and `ext-memcached` are needed only for those drivers.

## Usage

In a Laika app, through the relay:

```php
use Laika\Engine\Services\Cache;

Cache::set('stats', $stats, 600);
$stats = Cache::get('stats');
$rates = Cache::remember('rates', 300, fn () => $api->rates());
```

On its own, build the manager with the same array shape as `lf-config/cache.php`:

```php
use Laika\Engine\Cache\Cache;

$cache = new Cache(['driver' => 'file', 'path' => __DIR__ . '/cache', 'ttl' => 3600]);
```

| Method | |
|---|---|
| `get(string $key, mixed $default = null): mixed` | `$default` only on a miss |
| `set(string $key, mixed $value, ?int $ttl = null): bool` | `null` TTL uses the configured default, `0` never expires |
| `has(string $key): bool` | Present, whatever the value |
| `pop(string $key): bool` | Remove |
| `flush(): bool` | Remove every entry this cache owns |
| `increment()` / `decrement()` | A missing key counts as 0; the expiry is kept, not renewed |
| `remember(string $key, ?int $ttl, callable $fn): mixed` | Runs `$fn` only on a miss, even when it returns `null` |
| `forever()`, `pull()` | Store without expiry; read and remove |
| `store(string $driver)` | A driver other than the configured one |
| `extend(string $name, callable $resolver)` | Register your own driver |
| `resetProcess(): void` | Drop per-process state, for long-running workers |

## Guarantees

Every driver passes the same conformance suite, so these hold whichever one is configured:

- **A miss is not `null`.** A stored `null`, `false`, `0` or `''` is returned as itself, and `has()` reports it present.
- **Objects are not rebuilt from the cache by default.** Values are unserialized with `allowed_classes => false`; list classes in `serialize.allowed_classes` to opt in. The `array` driver serializes nothing and returns the instance you stored.
- **A backend that fails is a miss, not an exception.** Only misconfiguration throws `CacheException`.
- **The file driver is safe under concurrency.** Writes go through a temp file and `rename()`, so a reader never sees a partial entry, and `increment()` holds an exclusive lock — both proven against real parallel processes in `FileDriverConcurrencyTest`.

Driver-specific caveats: Redis and Memcached `increment()` are not atomic across processes. Memcached never reports a dead server, and cannot flush by prefix, so its `flush()` clears the whole server. Redis `flush()` deletes only this cache's prefix.

## Testing

```bash
composer install
vendor/bin/phpunit      # redis and memcached cases skip when no server is running
vendor/bin/phpcs --standard=phpcs.xml
```

## Documentation

[Caching](https://github.com/laikait/laika-framework/blob/main/docs/20_cache/01_basic.md) in the framework docs covers configuration, query-result caching, response caching and the `{% cache %}` Twig tag.

## License

MIT
