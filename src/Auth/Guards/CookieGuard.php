<?php
/**
 * Laika Auth
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Auth\Guards;

use Laika\Engine\Service\Cookie;
use Laika\Engine\Auth\Exceptions\AuthException;

class CookieGuard
{
    /** @var ?string Provider */
    protected ?string $provider;

    /** @var string $guard Name */
    protected string $guardName;

    /** @var string Cookie Name */
    protected string $cookieName;

    /**
     * @param array{name:string, provider?:?string} $config
     * @throws AuthException
     */
    public function __construct(array $config)
    {
        $name     = (string) ($config['name'] ?? '');
        $provider = $config['provider'] ?? null;

        // Check Name
        if ($name === '') {
            throw new AuthException('Cookie guard [name] key should not be empty');
        }

        // Check Provider. Optional, But When Given it Must be a Label
        if ($provider !== null && (!is_string($provider) || $provider === '')) {
            throw new AuthException("Cookie guard [provider] key should be a non-empty string in [{$name}]");
        }

        $this->provider = $provider;
        $this->guardName = $name;
        $this->cookieName = "laika_remember_{$name}";
    }

    /**
     * Remember Token
     * @param string $token
     * @param int $ttl Default is 2592000 (30 days)
     * @return void
     */
    public function remember(string $token, int $ttl = 2592000): void
    {
        Cookie::ttl($ttl)->set($this->cookieName, $token);
    }

    /**
     * Get Cookie Token
     *
     * Read raw rather than through Cookie::get(), which JSON-decodes: a token
     * such as "123" or "1e5" came back as a number, and a client-set "[]" as
     * an array, both a TypeError against this return type.
     * @return ?string
     */
    public function token(): ?string
    {
        $raw = $_COOKIE[$this->cookieName] ?? null;

        return is_string($raw) && $raw !== '' ? rawurldecode($raw) : null;
    }

    /**
     * Forget Cookie
     * @return void
     */
    public function forget(): void
    {
        Cookie::pop($this->cookieName);
    }
}
