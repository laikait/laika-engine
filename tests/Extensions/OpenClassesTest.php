<?php

declare(strict_types=1);

namespace Laika\Engine\Extensions\Tests;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Laika\Engine\Model\Model;
use Laika\Engine\Model\Connection;
use Laika\Engine\Model\Schema\Schema;
use Laika\Engine\Model\Schema\Blueprint;

/** Overrides a step that used to be private, to prove subclasses can reach it. */
class ShoutingModel extends Model
{
    protected string $table = 'users';

    protected function cast(array|object $row): array|object
    {
        $row = parent::cast($row);

        return $this->rowSet($row, 'name', strtoupper((string) $this->rowGet($row, 'name')));
    }
}

/**
 * Pins which classes are open for extension and which stay final on purpose,
 * so a class is never opened, or closed again, by accident.
 */
class OpenClassesTest extends TestCase
{
    /** @return array<string,array{class-string}> */
    public static function openClasses(): array
    {
        $classes = [
            \Laika\Engine\Shield\Shield::class,
            \Laika\Engine\Model\Connection::class,
            \Laika\Engine\Model\Schema\Schema::class,
            \Laika\Engine\Model\Converter::class,
            \Laika\Engine\App\Resource::class,
            \Laika\Engine\Relay\RelayBootstrap::class,
            \Laika\Engine\Cli\EntryPoints::class,
            \Laika\Engine\Helper\MimeType::class,
            \Laika\Engine\Nav\Helper\Renderer::class,
            \Laika\Engine\Generator\Icon::class,
            \Laika\Engine\Generator\Uid::class,
            \Laika\Engine\Log\Activity::class,
            \Laika\Engine\Template\FragmentCache::class,
            \Laika\Engine\System\MemoryManager::class,
            \Laika\Engine\Shield\Config\CountryConfig::class,
            \Laika\Engine\Shield\Config\IpConfig::class,
            \Laika\Engine\Shield\Config\RateLimitConfig::class,
            \Laika\Engine\Shield\Config\RequestFilterConfig::class,
            \Laika\Engine\Shield\Config\SqlInjectionConfig::class,
            \Laika\Engine\Shield\Config\XssConfig::class,
        ];

        return array_combine($classes, array_map(fn ($c) => [$c], $classes));
    }

    /** @return array<string,array{class-string}> */
    public static function securityBoundaries(): array
    {
        $classes = [
            \Laika\Engine\Route\Asset::class,
            \Laika\Engine\Http\ProxyTrust::class,
            \Laika\Engine\Shield\Support\IpHelper::class,
            \Laika\Engine\Shield\Support\RequestHelper::class,
            \Laika\Engine\Shield\Detectors\XssDetector::class,
            \Laika\Engine\Shield\Detectors\SqlInjectionDetector::class,
            \Laika\Engine\Shield\Rules\SqlInjectionRule::class,
            \Laika\Engine\Shield\Rules\XssRule::class,
        ];

        return array_combine($classes, array_map(fn ($c) => [$c], $classes));
    }

    /** @dataProvider openClasses */
    #[\PHPUnit\Framework\Attributes\DataProvider('openClasses')]
    public function testClassIsOpenForExtension(string $class): void
    {
        $reflection = new ReflectionClass($class);

        $this->assertFalse($reflection->isFinal(), "{$class} should be open for extension.");

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class) {
                $this->assertFalse($method->isPrivate(), "{$class}::{$method->name}() should be protected, not private.");
            }
        }
    }

    /** @dataProvider securityBoundaries */
    #[\PHPUnit\Framework\Attributes\DataProvider('securityBoundaries')]
    public function testSecurityBoundaryStaysFinal(string $class): void
    {
        $this->assertTrue((new ReflectionClass($class))->isFinal(), "{$class} is a security boundary and must stay final.");
    }

    public function testASubclassCanOverrideAFormerlyPrivateModelStep(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }

        Connection::purge();
        Connection::add(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::on()->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 20);
        });
        (new ShoutingModel())->insert(['name' => 'ada']);

        $this->assertSame('ADA', (new ShoutingModel())->get()[0]['name']);

        Connection::purge();
    }
}
