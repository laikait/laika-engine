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

use Laika\Engine\Model\Model;
use Laika\Engine\Services\Visitor;
use Laika\Engine\Auth\Model\AuthModel;
use Laika\Engine\Auth\Schema\AuthSchema;
use Laika\Engine\Auth\Exceptions\AuthException;

class TokenGuard
{
    /** @var string Guard Name */
    protected string $guardName;

    /** @var Model Provider Model */
    protected Model $provider;

    /** @var AuthModel Model */
    protected AuthModel $model;

    /**
     * @param array{name:string, provider:class-string<Model>, connection?:?string, install?:bool} $config
     * @throws AuthException
     */
    public function __construct(array $config)
    {
        $name     = (string) ($config['name'] ?? '');
        $provider = $config['provider'] ?? null;

        // Check Provider
        if (!is_string($provider) || $provider === '') {
            throw new AuthException("Token guard [provider] key should not be empty in [{$name}]");
        }

        // is_subclass_of() takes the class name itself: ::class on a string is a TypeError
        if (!is_subclass_of($provider, Model::class)) {
            throw new AuthException("Token guard provider class should be sub class of " . Model::class);
        }

        // Install Schema if Required. After validation, so a misconfigured guard creates nothing
        if ($config['install'] ?? false) (new AuthSchema($config['connection'] ?? null))->up();

        $this->guardName = strtolower($name);
        $this->provider = new $provider();
        $this->model = new AuthModel($config['connection'] ?? null);
    }

    /**
     * Issue Tiken
     * @param int $userId
     * @param ?int $ttl
     * @return array
     * @throws AuthException
     */
    public function issueToken(int $userId, ?int $ttl = null): array
    {
        $token = $this->generateToken();
        $hashed = hash('sha256', $token);
        // Hashed like the token. The plain value used to be stored and never
        // returned, so no client could ever present it.
        $refresh = bin2hex(random_bytes(32));
        $row = [
            'user_id' => $userId,
            'guard' => $this->guardName,
            'browser' => Visitor::browser(),
            'ip' => Visitor::ip(),
            'user_agent' => Visitor::userAgent(),
            'token' => $hashed,
            'refresh_token' => hash('sha256', $refresh),
            'expires_at' => $ttl ? date('Y-m-d H:i:s', time() + $ttl) : null,
        ];

        try {
            $this->model->transaction(function (AuthModel $m) use ($row) {
                $m->insert($row);
            });
        } catch (\Throwable $th) {
            throw $th;
        }
        return ['token' => $token, 'hashed' => $hashed, 'refresh_token' => $refresh];
    }

    /**
     * Exchange a Refresh Token For a New Token Pair
     *
     * The old token is revoked, so each refresh token works once. An expired
     * token can still be refreshed; a revoked one can't.
     * @param string $refreshToken Plain refresh token returned by issueToken()
     * @param ?int $ttl Lifetime of the new token in seconds. Null never expires, as in issueToken()
     * @return ?array Same shape as issueToken(), or null
     * @throws AuthException
     */
    public function refreshToken(string $refreshToken, ?int $ttl = null): ?array
    {
        if ($refreshToken === '') return null;

        $row = $this->model
                    ->select(['id', 'user_id'])
                    ->where(['refresh_token' => hash('sha256', $refreshToken), 'guard' => $this->guardName])
                    ->isNull('revoked_at')
                    ->first();

        // Check Has Row & User
        if (empty($row)) return null;
        if (empty($this->provider->find($row['user_id']))) return null;

        // Revoke only while still live: when two requests race with the same
        // refresh token, exactly one update matches and only that one issues.
        $revoked = $this->model
                        ->where(['id' => $row['id']])
                        ->isNull('revoked_at')
                        ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        if ((int) $revoked === 0) return null;

        return $this->issueToken((int) $row['user_id'], $ttl);
    }


    /**
     * Validate Token
     * @param ?int $ttl Default is 3600 (1 Hour)
     * @param ?string $token
     * @param bool $strict Default is false
     * @return ?array
     */
    public function validateToken(?string $token, ?int $ttl = null, bool $strict = false): ?array
    {
        if (empty($token)) return null;
        $hashed = hash('sha256', $token);
        $row = $this->model
                    ->select(['expires_at', 'user_id', 'browser', 'ip', 'user_agent'])
                    ->where(['token' => $hashed, 'guard' => $this->guardName])
                    ->isNull('revoked_at')
                    ->first();

        // Check Has Row
        if (empty($row)) return null;

        // Check Not Expired
        if ($row['expires_at']) {
            if (strtotime($row['expires_at']) < time()) return null;
            $ttl = $ttl ?: 3600;
            $this->model
                ->where(['token' => $hashed, 'guard' => $this->guardName])
                ->update(['expires_at' => date('Y-m-d H:i:s', time() + $ttl)]);
        }

        // Validate Visitor Info
        if ($strict) {
            // Validate Browser
            if ($row['browser'] != Visitor::browser()) return null;

            // Validate Browser
            if ($row['user_agent'] != Visitor::userAgent()) return null;

            // Validate Browser
            if ($row['ip'] != Visitor::ip()) return null;
        }

        $user = $this->provider->find($row['user_id']);

        // Check Has User
        if (empty($user)) return null;
        return $user;
    }

    /**
     * Revoke Tiken
     * @param string $plainToken
     * @return bool
     */
    public function revoke(string $plainToken): bool
    {
        return (bool) $this->model
                            ->where(['token' => hash('sha256', $plainToken), 'guard' => $this->guardName])
                            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Revoke Tiken
     * @param int $userId
     * @return bool
     */
    public function revokeAllForUser(int $userId): bool
    {
        return (bool) $this->model
                            ->where(['user_id' => $userId, 'guard' => $this->guardName])
                            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    ############################################################################
    /*============================= INTERNAL API =============================*/
    ############################################################################
    /**
     * Generate Token
     * @param ?int $byte
     * @return string
     */
    private function generateToken(?int $byte = null): string
    {
        $byte = $byte ?: 24;
        $token = bin2hex(random_bytes($byte));

        if ($this->model->select('id')->where(['token' => hash('sha256', $token), 'guard' => $this->guardName])->count() > 0) {
            return $this->generateToken($byte);
        }
        return $token;
    }
}
