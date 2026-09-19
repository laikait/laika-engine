<?php

declare(strict_types=1);

namespace Laika\Engine\Extensions\Tests;

use BadMethodCallException;
use PHPUnit\Framework\TestCase;
use Laika\Engine\Model\Model;
use Laika\Engine\Model\Connection;
use Laika\Engine\Model\Schema\Schema;
use Laika\Engine\Model\Schema\Blueprint;
use Laika\Engine\Relay\Relay;
use Laika\Engine\Relay\RelayRegistry;
use Laika\Engine\Support\Macroable;

class MacroTarget
{
    use Macroable;

    protected string $secret = 'protected value';
}

class MacroTargetChild extends MacroTarget
{
}

class MacroRelay extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'macro.target';
    }
}

class MacroUsersModel extends Model
{
    protected string $table = 'users';
}

class MacroableTest extends TestCase
{
    protected function tearDown(): void
    {
        MacroTarget::flushMacros();
        MacroTargetChild::flushMacros();
        Model::flushMacros();
        Connection::purge();
    }

    public function testAnInstanceMacroIsBoundToThatInstance(): void
    {
        MacroTarget::macro('reveal', function () {
            return $this->secret;
        });

        $this->assertSame('protected value', (new MacroTarget())->reveal());
    }

    public function testAStaticMacroIsCalledOnTheClass(): void
    {
        MacroTarget::macro('twice', fn (int $n) => $n * 2);

        $this->assertSame(8, MacroTarget::twice(4));
    }

    public function testAStaticClosureMacroStillWorksOnAnInstance(): void
    {
        MacroTarget::macro('plain', static fn () => 'ok');

        $this->assertSame('ok', (new MacroTarget())->plain());
    }

    public function testMacrosAreInheritedButNotSharedUpwards(): void
    {
        MacroTarget::macro('fromParent', fn () => 'parent');
        MacroTargetChild::macro('fromChild', fn () => 'child');

        $this->assertSame('parent', (new MacroTargetChild())->fromParent());
        $this->assertTrue(MacroTargetChild::hasMacro('fromChild'));
        $this->assertFalse(MacroTarget::hasMacro('fromChild'));
    }

    public function testMixinRegistersEveryMethod(): void
    {
        MacroTarget::mixin(new class {
            public function shout(): \Closure
            {
                return fn (string $s) => strtoupper($s);
            }
        });

        $this->assertSame('HI', (new MacroTarget())->shout('hi'));
    }

    public function testAnUnknownMethodStillFails(): void
    {
        $this->expectException(BadMethodCallException::class);
        (new MacroTarget())->missing();
    }

    public function testARelayForwardsToAMacro(): void
    {
        MacroTarget::macro('viaRelay', fn () => 'relayed');

        $original = Relay::getRegistry();
        $registry = new RelayRegistry();
        $registry->instance('macro.target', new MacroTarget());
        Relay::swapRegistry($registry);

        try {
            $this->assertSame('relayed', MacroRelay::viaRelay());
        } finally {
            Relay::swapRegistry($original);
        }
    }

    public function testAModelMacroCanBuildOnTheQueryBuilder(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }

        Connection::add(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::on()->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('role', 20);
        });
        (new MacroUsersModel())->insert([['role' => 'admin'], ['role' => 'member'], ['role' => 'member']]);

        Model::macro('members', function () {
            return $this->where(['role' => 'member']);
        });

        $this->assertSame(2, (new MacroUsersModel())->members()->count());
    }
}
