# Laika Engine

The whole Laika PHP Framework runtime in one Composer package. It replaces the
separate `laikait/laika-*` packages.

```bash
composer require laikait/laika-engine
```

Requires PHP 8.1+ with `ext-json`, `ext-mbstring`, `ext-openssl` and `ext-pdo`.

## Modules

Everything lives under the `Laika\Engine\` namespace.

| Module    | Namespace                  | Replaces               | Docs                   |
|-----------|----------------------------|------------------------|------------------------|
| Core      | `Laika\Engine\Core`        | `laikait/laika-core`    | [docs/core](docs/core)       |
| Route     | `Laika\Engine\Route`       | `laikait/laika-route`   | [docs/route](docs/route)     |
| Relay     | `Laika\Engine\Relay`       | `laikait/laika-relay`   | [docs/relay](docs/relay)     |
| Services  | `Laika\Engine\Services`    | `laikait/laika-relay` (services) | [docs/relay](docs/relay) |
| Model     | `Laika\Engine\Model`       | `laikait/laika-model`   | [docs/model](docs/model)     |
| Session   | `Laika\Engine\Session`     | `laikait/laika-session` | [docs/session](docs/session) |
| Auth      | `Laika\Engine\Auth`        | `laikait/laika-auth`    | [docs/auth](docs/auth)       |
| Cache     | `Laika\Engine\Cache`       | `laikait/laika-cache`   | [docs/cache](docs/cache)     |
| Queue     | `Laika\Engine\Queue`       | `laikait/laika-queue`   | [docs/queue](docs/queue)     |
| Mailman   | `Laika\Engine\Mailman`     | `laikait/laika-mailman` | [docs/mailman](docs/mailman) |
| Shield    | `Laika\Engine\Shield`      | `laikait/laika-shield`  | [docs/shield](docs/shield)   |
| Cli       | `Laika\Engine\Cli`         | `laikait/laika-cli`     | [docs/cli](docs/cli)         |

The package ships two executables, `bin/laika` (CLI) and `bin/worker`
(queue worker). A Laika project also gets root-level `laika` and `worker`
proxies, which Composer generates through the project's `post-autoload-dump`
scripts.

## Migrating from the laika-* packages

1. Replace the requirements in the project's `composer.json`:

   ```json
   "require": {
       "php": ">=8.1",
       "laikait/laika-engine": "1.0.*"
   }
   ```

2. Point the scripts at the new namespace:

   ```json
   "post-autoload-dump": [
       "Laika\\Engine\\Cli\\ScriptHandler::generate",
       "Laika\\Engine\\Queue\\ScriptHandler::generate",
       "@php laika app:sync"
   ]
   ```

3. Prefix every framework import with `Engine\`, for example
   `use Laika\Route\Url;` becomes `use Laika\Engine\Route\Url;`. This one-liner
   does it for a whole project:

   ```bash
   grep -rlP 'Laika\\+(Auth|Cache|Cli|Core|Mailman|Model|Queue|Relay|Route|Session|Shield|Service)\b' \
       lf-* public template | xargs perl -pi -e \
       's/Laika(\\+)(Auth|Cache|Cli|Core|Mailman|Model|Queue|Relay|Route|Session|Shield|Service)\b/Laika$1Engine$1$2/g'
   ```

4. Move `index.php` into `public/` and point the web server's document root at
   `public/`. `php laika app:sync` writes `public/.htaccess`, and
   `php laika nginx:server` emits a server block rooted at `public/`.

## Extending

- **Drivers:** `Cache::extend()`, `HandlerFactory::extend()` (sessions),
  `Queue::extend()` / `Queue::extendFailed()`, `DriverFactory::extend()`
  (database) and `MailManager::extendMailer()` / `extendReader()` register
  backends by name.
- **Macros:** `Model`, `Blueprint`, `Request`, `Response`, `Cache` and `Url` use
  `Laika\Engine\Core\Support\Macroable`, so `Model::macro('active', fn () => ...)`
  adds a method. Relays forward macros too.
- **Subclassing:** framework classes are open and their steps are `protected`.
  Security boundaries (`Route\Asset`, `ProxyTrust`, the Shield detectors and
  rules), value objects and internal parts stay `final`, and say why.

The framework docs cover each in detail, under "Extending the Framework".

## Package resources

A package can declare the same resource from several directories by giving a
list. Laika Engine does this for relays:

```json
"extra": {
    "laika": {
        "resources": {
            "relays": [
                {"path": "src/Cache/Relay", "namespace": "...", "contract": "..."},
                {"path": "src/Shield/Relay", "namespace": "...", "contract": "..."}
            ]
        }
    }
}
```

## Development

```bash
composer install
composer test
```

Each module's suite runs in its own process, because the suites need different
`APP_PATH` values (see `phpunit.xml.dist`).

## License

MIT
