<?php
/**
 * Laika Framework Relay Service
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 */

declare(strict_types=1);

namespace Laika\Engine\Relay;

use Laika\Engine\App\Resource;
use Laika\Engine\App\ResourceDefinition;
use Laika\Engine\Cache\Relay\CacheRelay;

/**
 * RelayBootstrap — Decides Which Providers Are Registered, And In What Order.
 *
 * helpers/loader.php builds the container; this class answers the only hard
 * question in that sequence: given the framework's own providers and whatever
 * packages and the application declare, which classes get registered and in
 * which order. It deliberately does no global wiring -- no Relay::setRegistry(),
 * no resolver -- so it can be exercised against a throwaway registry. The
 * loader keeps the wiring, where the order of the whole bootstrap is visible.
 *
 * Registration order is the framework's override mechanism:
 * RelayRegistry::singleton() is last-write-wins, so whoever registers last for
 * a given key wins. Core first, then packages, then the application.
 */
class RelayBootstrap
{
    /**
     * Build The Provider Registry For a Container
     *
     * Returns it *unbooted* -- the caller boots once the rest of the bootstrap
     * is wired, which is what RelayProvider's two-phase contract requires.
     *
     * @param RelayRegistry $registry Container the providers bind into
     * @return ProviderRegistry
     */
    public static function providers(RelayRegistry $registry): ProviderRegistry
    {
        $providers = new ProviderRegistry($registry);

        foreach ([...static::core(), ...static::discovered()] as $class) {
            $providers->register($class);
        }

        return $providers;
    }

    /**
     * Providers The Framework Always Registers
     *
     * CacheRelay is also declared as a `relays` resource, so discovery finds it
     * too. Naming it here as well keeps the cache a core service even when a
     * compiled manifest is stale, and costs nothing: ProviderRegistry::register()
     * de-duplicates by class name. It stays ahead of discovery so an application
     * provider binding 'cache' still wins.
     *
     * @return class-string<RelayProvider>[]
     */
    protected static function core(): array
    {
        return [
            CoreProviders::class,
            CacheRelay::class,
        ];
    }

    /**
     * Provider Classes Declared By Packages And The Application
     *
     * @return class-string<RelayProvider>[]
     */
    protected static function discovered(): array
    {
        $classes = [];

        foreach (static::order(Resource::definitions('relays')) as $definition) {
            foreach (Resource::entries($definition) as $class) {
                if (static::isProvider($class, $definition->contract)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Sort Definitions So The Application Registers Last
     *
     * Pure on purpose: ordering is the part worth testing, and this way it can
     * be tested without a container or a filesystem.
     *
     * @param ResourceDefinition[] $definitions
     * @return ResourceDefinition[]
     */
    protected static function order(array $definitions): array
    {
        $packages = [];
        $application = [];

        foreach ($definitions as $definition) {
            if (static::isApplication($definition)) {
                $application[] = $definition;
            } else {
                $packages[] = $definition;
            }
        }

        return [...$packages, ...$application];
    }

    /**
     * Is This Definition The Application's Own Relay Directory
     *
     * Two sources mean "the application", and the difference between them is
     * only who declared it. 'app' is a directory the application named in its
     * own composer.json; 'default' is lf-app/Relay, which Resource seeds on the
     * application's behalf when its composer.json names nothing. Both must
     * register after packages -- that is what lets an application rebind a
     * service a package already bound.
     *
     * @param ResourceDefinition $definition
     * @return bool
     */
    protected static function isApplication(ResourceDefinition $definition): bool
    {
        return in_array($definition->source, ['app', 'default'], true);
    }

    /**
     * Can This Class Name Be Registered As a Provider
     *
     * Skips rather than throws, and that is not politeness: this runs during
     * autoload, before CoreProviders::boot() installs the error handler, so a
     * throw here is an uncatchable fatal with no error page. A class that is
     * missing or does not extend RelayProvider is reported by
     * `php laika resource:list`, which is the place to look when a provider
     * seems not to load.
     *
     * The contract comes from the definition rather than being hardcoded, so it
     * cannot drift from the one Resource::defaults() declares for `relays`.
     *
     * @param string $class Class name from the resource scan
     * @param ?string $contract Contract the definition declares, if any
     * @return bool
     */
    protected static function isProvider(string $class, ?string $contract): bool
    {
        return class_exists($class) && is_subclass_of($class, $contract ?? RelayProvider::class);
    }
}
