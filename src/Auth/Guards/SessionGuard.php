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

use Laika\Engine\Session\Scope;
use Laika\Engine\Session\Session;
use Laika\Engine\Auth\Exceptions\AuthException;

class SessionGuard
{
    /** @var ?string Provider */
    protected ?string $provider;

    /** @var string Guard */
    protected string $guardName;

    /** @var string Session Key */
    protected string $sessionKey;

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
            throw new AuthException('Session guard [name] key should not be empty');
        }

        // Check Provider. Optional, But When Given it Names The Session Scope
        if ($provider !== null && (!is_string($provider) || $provider === '')) {
            throw new AuthException("Session guard [provider] key should be a non-empty string in [{$name}]");
        }

        $this->provider = $provider;
        $this->guardName = $name;
        $this->sessionKey = "laika_auth_{$name}";
    }

    /**
     * Make Login
     * @param array $user
     * @return void
     */
    public function login(array $user): void
    {
        $this->scope()->set($this->sessionKey, $user);
    }

    /**
     * Get User
     * @return array
     */
    public function user(): ?array
    {
        return $this->scope()->get($this->sessionKey);
    }

    /**
     * Logout
     * @return void
     */
    public function logout(): void
    {
        $this->scope()->pop($this->sessionKey);
    }

    /**
     * Session Scope For This Provider
     * The Same Slot v5's $for Parameter Wrote, So Existing Logins Survive. A
     * null Provider Used to be a TypeError Under strict_types
     * @return Scope
     */
    protected function scope(): Scope
    {
        return Session::scope($this->provider ?? 'APP');
    }
}
