# Laika Auth

Multi-guard authentication for the [Laika PHP MVC Framework](https://github.com/laikait/laika-framework): session logins, "remember me" cookies and hashed bearer tokens, each resolved by name from `lf-config/auth.php`.

## Features

- Three guard drivers: `session`, `cookie` and `token`
- Bearer tokens stored only as SHA-256 hashes, in an `auth_tokens` table
- Token issue, validate (with optional sliding expiry and a strict client check), revoke and revoke-all
- Browser, IP and user-agent recorded per token
- Guards defined in config, resolved and cached by `AuthManager`

Guards don't check passwords or protect routes on their own. Verify credentials yourself (for example with `Vault::verifyPassword()`), then call the guard from a controller or pipeline.

## Installation

```bash
composer require laikait/laika-auth
```

laika-core requires this package, so a Laika app already has it. The session guard needs `laikait/laika-session`, and the token guard needs `laikait/laika-model`.

## Configuration

`lf-config/auth.php` is keyed directly by guard name:

```php
use App\Model\UsersModel;

return [
    'web'      => ['driver' => 'session', 'provider' => 'web'],
    'remember' => ['driver' => 'cookie',  'provider' => 'remember'],
    'user'     => [
        'driver'     => 'token',
        'provider'   => UsersModel::class,
        'connection' => 'default', // where auth_tokens lives
        'install'    => false,     // true creates auth_tokens on first use (development only)
    ],
];
```

What `provider` means depends on the driver:

| Driver | `provider` |
|---|---|
| `session` | Optional. The session scope the user is stored in. Without it, the `APP` scope. |
| `cookie` | Unused. The cookie is always named `laika_remember_{guard}`. |
| `token` | Required. A `Laika\Engine\Model\Model` subclass. `validateToken()` loads the user with its `find()`. |

## Usage

```php
use Laika\Engine\Auth\AuthManager;

$auth = new AuthManager();   // reads config('auth'), or pass the array yourself
```

`guard(string $name)` returns a `SessionGuard`, `CookieGuard` or `TokenGuard`, cached per manager. An unknown name throws `InvalidArgumentException`.

### Session Guard

```php
$guard = $auth->guard('web');

$guard->login(['id' => 42, 'email' => 'ann@example.com']);
$user = $guard->user();   // the array you stored, or null
$guard->logout();
```

There's no `check()` or `id()`: use `user() !== null` and `user()['id']`.

### Cookie Guard

```php
$guard = $auth->guard('remember');

$guard->remember($token);              // 30 days
$guard->remember($token, ttl: 86400);
$token = $guard->token();              // null when absent
$guard->forget();
```

The guard only stores the token. Generating it, saving a hash of it against the user, and checking it on return are up to you.

### Token Guard

```php
$guard = $auth->guard('user');

$issued = $guard->issueToken($userId, ttl: 3600); // ['token' => '…', 'hashed' => '…', 'refresh_token' => '…']
$user   = $guard->validateToken($plainToken);     // the user row, or null
$issued = $guard->refreshToken($refreshToken, ttl: 3600); // a new pair, or null
$guard->revoke($plainToken);
$guard->revokeAllForUser($userId);
```

- **Without a `ttl`, a token never expires.**
- For a token that has an expiry, each successful `validateToken()` pushes it to now + `$ttl` (3600 seconds by default).
- `validateToken($token, strict: true)` also requires the browser, user agent and IP recorded when the token was issued.
- Tokens are scoped to their guard: a token issued by `user` doesn't validate on another guard.
- Refresh tokens are stored hashed and work once: `refreshToken()` revokes the old token and issues a new pair. An expired token can be refreshed; a revoked one can't. Pass the same `ttl` you issue with — `null` never expires.

## The `auth_tokens` Table

`php laika app:migrate` doesn't create it. Either set `'install' => true` on the guard once, or run the schema with a privileged connection:

```php
(new \Laika\Engine\Auth\Schema\AuthSchema('default'))->up();
```

Columns: `id`, `user_id`, `guard`, `browser`, `ip`, `user_agent`, `token` (unique, the SHA-256 hash), `refresh_token`, `expires_at`, `revoked_at`, `created_at`, plus an index on `(user_id, guard)`.

Leave `install` off in production. It runs on every request that resolves the guard, and needs DDL rights the runtime database user shouldn't have.

## Errors

`Laika\Engine\Auth\Exceptions\AuthException` is thrown for a misconfigured guard, such as a token provider that isn't a `Model`. Failed validation doesn't throw; it returns `null`.

## Documentation

The full guide, including login controllers and route-protecting pipelines, is [Authentication](https://github.com/laikait/laika-framework/blob/main/docs/09_authentication/01_basic.md) in the framework docs.

## License

MIT
