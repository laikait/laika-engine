<?php

declare(strict_types=1);

namespace Laika\Engine\Tests\Unit;

use Laika\Engine\App\ResourceDefinition;
use Laika\Engine\Cache\Relay\CacheRelay;
use Laika\Engine\Relay\CoreProviders;
use Laika\Engine\Relay\RelayBootstrap;
use Laika\Engine\Relay\RelayProvider;
use Laika\Engine\Relay\RelayRegistry;
use Laika\Engine\Tests\Fixtures\Relay\NotAProvider;
use Laika\Engine\Tests\Fixtures\Relay\SampleProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Registration order is the framework's override mechanism -- singleton() is
 * last-write-wins, so whoever registers last for a key wins. None of that was
 * covered while it lived in helpers/loader.php, because an autoloaded
 * procedural file cannot be exercised.
 */
final class RelayBootstrapTest extends TestCase
{
    /** Reach a protected static; the class exposes only providers() by design */
    private static function call(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod(RelayBootstrap::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    private static function definition(string $source): ResourceDefinition
    {
        // Built directly rather than through Resource::register(), which
        // hardcodes the source to 'runtime' and so cannot express the case
        // under test
        return new ResourceDefinition(
            'relays',
            '/nowhere/' . $source,
            'App\\Relay',
            RelayProvider::class,
            $source
        );
    }

    /*============================== ORDERING ==============================*/

    public function testApplicationDefinitionsRegisterAfterPackages(): void
    {
        $ordered = self::call('order', [[
            self::definition('default'),
            self::definition('laikait/laika-auth'),
            self::definition('app'),
            self::definition('acme/widgets'),
        ]]);

        self::assertSame(
            ['laikait/laika-auth', 'acme/widgets', 'default', 'app'],
            array_map(static fn (ResourceDefinition $d): string => $d->source, $ordered)
        );
    }

    public function testOrderIsStableWithinEachGroup(): void
    {
        // Packages keep the order Resource seeded them in, which is what makes
        // a later package able to override an earlier one
        $ordered = self::call('order', [[
            self::definition('a/one'),
            self::definition('b/two'),
            self::definition('c/three'),
        ]]);

        self::assertSame(
            ['a/one', 'b/two', 'c/three'],
            array_map(static fn (ResourceDefinition $d): string => $d->source, $ordered)
        );
    }

    public function testOrderKeepsEveryDefinition(): void
    {
        $given = [self::definition('app'), self::definition('x/y'), self::definition('default')];

        self::assertCount(3, self::call('order', [$given]));
    }

    public function testEmptyInputOrdersToNothing(): void
    {
        self::assertSame([], self::call('order', [[]]));
    }

    /*=========================== SOURCE MEANING ===========================*/

    /**
     * @dataProvider applicationSources
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('applicationSources')]
    public function testApplicationOwnedSources(string $source, bool $expected): void
    {
        self::assertSame($expected, self::call('isApplication', [self::definition($source)]));
    }

    /** @return array<string,array{string,bool}> */
    public static function applicationSources(): array
    {
        return [
            // The application named the directory in its own composer.json
            'app declared by the application' => ['app', true],
            // Resource seeded lf-app/Relay on the application's behalf
            'lf-app/Relay seeded as default' => ['default', true],
            'an installed package'           => ['laikait/laika-auth', false],
            'registered at runtime'          => ['runtime', false],
        ];
    }

    /*========================== PROVIDER FILTER ==========================*/

    public function testARealProviderIsAccepted(): void
    {
        self::assertTrue(self::call('isProvider', [SampleProvider::class, RelayProvider::class]));
    }

    public function testAClassThatIsNotAProviderIsRejected(): void
    {
        self::assertFalse(self::call('isProvider', [NotAProvider::class, RelayProvider::class]));
    }

    public function testAMissingClassIsSkippedRatherThanThrowing(): void
    {
        // This runs during autoload, before the error handler exists, so a
        // throw here would be an uncatchable fatal with no error page
        self::assertFalse(self::call('isProvider', ['No\\Such\\Provider', RelayProvider::class]));
    }

    public function testANullContractFallsBackToRelayProvider(): void
    {
        self::assertTrue(self::call('isProvider', [SampleProvider::class, null]));
        self::assertFalse(self::call('isProvider', [NotAProvider::class, null]));
    }

    public function testTheContractComesFromTheDefinitionNotAHardcodedOne(): void
    {
        // A definition declaring a narrower contract must be honoured, which is
        // what stops this filter drifting from Resource::defaults()
        self::assertFalse(self::call('isProvider', [SampleProvider::class, CoreProviders::class]));
    }

    /*========================== CORE PROVIDERS ==========================*/

    public function testCoreProvidersAreRegisteredAheadOfDiscovery(): void
    {
        $core = self::call('core');

        self::assertSame([CoreProviders::class, CacheRelay::class], $core);
    }

    /*============================== WIRING ==============================*/

    public function testProvidersBindIntoTheGivenRegistryWithoutTouchingGlobalState(): void
    {
        // A throwaway registry: providers() does no global wiring, which is the
        // whole reason it takes the registry as an argument
        $registry = new RelayRegistry();

        $providers = RelayBootstrap::providers($registry);

        self::assertTrue($providers->has(CoreProviders::class));
        self::assertTrue($providers->has(CacheRelay::class));
        self::assertTrue($registry->has('config'), 'CoreProviders should have bound its services.');
    }

    public function testProvidersAreReturnedUnbooted(): void
    {
        // boot() is the caller's call -- the loader wires the router between
        // register and boot, and RelayProvider's contract depends on that gap
        $registry = new RelayRegistry();

        $providers = RelayBootstrap::providers($registry);

        self::assertNotSame([], $providers->providers());
    }

    public function testRegisteringTwiceDoesNotDuplicateAProvider(): void
    {
        $registry = new RelayRegistry();

        $providers = RelayBootstrap::providers($registry);
        $before    = count($providers->providers());

        // CacheRelay is named in core() *and* discovered as a relays resource;
        // ProviderRegistry de-duplicating by class name is what makes that safe
        $providers->register(CacheRelay::class);

        self::assertCount($before, $providers->providers());
    }
}
