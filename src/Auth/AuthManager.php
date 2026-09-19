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

namespace Laika\Engine\Auth;

use Laika\Engine\Auth\Guards\SessionGuard;
use Laika\Engine\Auth\Guards\CookieGuard;
use Laika\Engine\Auth\Guards\TokenGuard;

class AuthManager
{
    /** @var array Config Data */
    protected array $config;

    /** @var array Resolved Guard */
    protected array $resolved = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('auth');
    }

    /**
     * Get Guard object
     * @param string $name
     * @return SessionGuard|CookieGuard|TokenGuard
     */
    public function guard(string $name): SessionGuard|CookieGuard|TokenGuard
    {
        if (isset($this->resolved[$name])) return $this->resolved[$name];

        $conf = $this->config[$name]
            ?? throw new \InvalidArgumentException("Guard [$name] not configured.");
        $conf['name'] = $name;

        $guard = match ($conf['driver']) {
            'session'   =>  new SessionGuard($conf),
            'cookie'    =>  new CookieGuard($conf),
            'token'     =>  new TokenGuard($conf),
            default     =>  throw new \InvalidArgumentException("Unknown auth driver [{$conf['driver']}]."),
        };

        return $this->resolved[$name] = $guard;
    }
}
