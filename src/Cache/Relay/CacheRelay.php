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

namespace Laika\Engine\Cache\Relay;

use Laika\Engine\Cache\Cache;
use Laika\Engine\Relay\Relay;
use Laika\Engine\Relay\RelayProvider;

/**
 * Binds the cache into the container, and is the one place that knows the
 * host framework exists.
 *
 * Everything under src/ takes its configuration as an array, so the package
 * itself has no opinion about where config comes from. This reads
 * lf-config/cache.php and hands it over -- keeping the coupling at the seam
 * rather than through the whole package.
 */
class CacheRelay extends RelayProvider
{
    public function register(): void
    {
        // laika-core requires this package, so this package can never require
        // the framework back without a Composer cycle. The relay contract is
        // therefore only assumed present, never guaranteed.
        if (!class_exists(Relay::class)) {
            return;
        }

        // A host that already bound the cache keeps its binding
        if ($this->registry->has('cache')) {
            return;
        }

        // A factory, not the class name: the registry would otherwise build
        // Cache with an empty config, and driver, TTL and prefix would all
        // silently fall back to their defaults.
        $this->registry->singleton('cache', fn (): Cache => new Cache(static::settings()));
    }

    /**
     * Read lf-config/cache.php
     *
     * config() is a laika-core global. It is there whenever the framework
     * booted, and absent when the package is used on its own -- in which case
     * the built-in defaults apply.
     *
     * @return array
     */
    public static function settings(): array
    {
        if (!function_exists('config')) {
            return [];
        }

        $settings = (array) (config('cache') ?? []);

        // Redis and Memcached settings already live in their own config files,
        // which the queue and session drivers read too. Fall through to them
        // rather than making the user state the same host twice.
        foreach (['redis', 'memcached'] as $backend) {
            $settings['connections'][$backend] = array_merge(
                (array) (config($backend) ?? []),
                (array) ($settings['connections'][$backend] ?? [])
            );
        }

        return $settings;
    }
}
