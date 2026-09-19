<?php

declare(strict_types=1);

namespace Laika\Engine\Extensions\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Laika\Engine\Worker\Queue;
use Laika\Engine\Mailman\Mailer;
use Laika\Engine\Mailman\MailManager;
use Laika\Engine\Mailman\Reader\Pop3Reader;
use Laika\Engine\Mailman\Interfaces\MailerInterface;
use Laika\Engine\Mailman\Exceptions\MailmanException;
use Laika\Engine\Mailman\Interfaces\MailReaderInterface;
use Laika\Engine\Model\Drivers\DriverFactory;
use Laika\Engine\Model\Drivers\DriverInterface;
use Laika\Engine\Model\Exceptions\DriverException;
use Laika\Engine\Queue\Interfaces\QueueDriverInterface;
use Laika\Engine\Queue\Interfaces\FailedJobProviderInterface;
use Laika\Engine\Session\SessionConfig;
use Laika\Engine\Session\Handler\FileHandler;
use Laika\Engine\Session\Handler\HandlerFactory;
use Laika\Engine\Session\Contracts\SessionDriverInterface;
use Laika\Engine\Session\Exceptions\SessionHandlerException;

/**
 * Every driver registry takes an application driver through extend(), checks
 * what the resolver returns, and still builds its built-in drivers.
 */
class DriverExtendTest extends TestCase
{
    protected function tearDown(): void
    {
        HandlerFactory::flushExtensions();
        SessionConfig::reset();
        Queue::flushExtensions();
        MailManager::flushExtensions();
        DriverFactory::unregister('custom');
        DriverFactory::unregister('sqlite');
    }

    ##########################################################################
    /*=============================== SESSION ==============================*/
    ##########################################################################

    public function testSessionBuildsAnExtendedDriverWithItsParams(): void
    {
        $handler = $this->createMock(SessionDriverInterface::class);
        $received = null;

        HandlerFactory::extend('Custom', function (array $params) use ($handler, &$received) {
            $received = $params;
            return $handler;
        });

        SessionConfig::custom('custom', ['prefix' => 'X']);

        $this->assertSame('custom', SessionConfig::driver());
        $this->assertSame($handler, HandlerFactory::make(SessionConfig::driver(), SessionConfig::params()));
        $this->assertSame(['prefix' => 'X'], $received);
        $this->assertContains('custom', HandlerFactory::drivers());
    }

    public function testSessionRejectsAnUnregisteredCustomDriver(): void
    {
        $this->expectException(SessionHandlerException::class);
        SessionConfig::custom('nope');
    }

    public function testSessionRejectsAResolverThatReturnsTheWrongType(): void
    {
        HandlerFactory::extend('broken', fn () => new \stdClass());

        $this->expectException(SessionHandlerException::class);
        HandlerFactory::make('broken');
    }

    public function testSessionStillBuildsBuiltInDrivers(): void
    {
        $this->assertInstanceOf(FileHandler::class, HandlerFactory::make('file', ['path' => sys_get_temp_dir()]));
    }

    ##########################################################################
    /*================================ QUEUE ===============================*/
    ##########################################################################

    public function testQueueBuildsTheExtendedDriverNamedInConfig(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $received = null;

        Queue::extend('fake', function (array $config) use ($driver, &$received) {
            $received = $config;
            return $driver;
        });

        $this->assertSame($driver, Queue::driver());
        $this->assertSame('from-config', $received['marker'] ?? null, 'The resolver receives lf-config/queue.php.');
    }

    public function testQueueBuildsTheExtendedFailedProviderNamedInConfig(): void
    {
        $provider = $this->createMock(FailedJobProviderInterface::class);
        Queue::extendFailed('fake-failed', fn () => $provider);

        $this->assertSame($provider, Queue::failedProvider());
    }

    public function testQueueRejectsAResolverThatReturnsTheWrongType(): void
    {
        Queue::extend('fake', fn () => new \stdClass());

        $this->expectException(RuntimeException::class);
        Queue::driver();
    }

    ##########################################################################
    /*================================ MODEL ===============================*/
    ##########################################################################

    public function testModelBuildsAnExtendedDriverFromTheConnectionConfig(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $received = null;

        DriverFactory::extend('custom', function (array $config) use ($driver, &$received) {
            $received = $config;
            return $driver;
        });

        $this->assertSame($driver, DriverFactory::make(['driver' => 'custom', 'host' => 'db']));
        $this->assertSame('db', $received['host']);
        $this->assertContains('custom', DriverFactory::supported());
    }

    public function testModelExtendCanReplaceABuiltInDriver(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        DriverFactory::extend('sqlite', fn () => $driver);

        $this->assertSame($driver, DriverFactory::make(['driver' => 'sqlite']));
    }

    public function testModelUnregisterRemovesAnExtendedDriver(): void
    {
        DriverFactory::extend('custom', fn () => $this->createMock(DriverInterface::class));

        $this->assertTrue(DriverFactory::unregister('custom'));
        $this->expectException(DriverException::class);
        DriverFactory::make(['driver' => 'custom']);
    }

    ##########################################################################
    /*=============================== MAILMAN ==============================*/
    ##########################################################################

    public function testMailmanBuildsAnExtendedMailer(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        MailManager::extendMailer('log', fn (array $config) => $mailer);

        $this->assertSame($mailer, MailManager::mailer(['driver' => 'log']));
        $this->assertContains('log', MailManager::mailerDrivers());
    }

    public function testMailmanBuildsAnExtendedReader(): void
    {
        $reader = $this->createMock(MailReaderInterface::class);
        MailManager::extendReader('jmap', fn (array $config) => $reader);

        $this->assertSame($reader, MailManager::reader(['protocol' => 'jmap']));
    }

    public function testMailmanStillBuildsBuiltIns(): void
    {
        $this->assertInstanceOf(Mailer::class, MailManager::mailer(['driver' => 'SMTP']));
        $this->assertInstanceOf(Pop3Reader::class, MailManager::reader(['protocol' => 'pop3']));
    }

    public function testMailmanRejectsAnUnknownDriver(): void
    {
        $this->expectException(MailmanException::class);
        MailManager::mailer(['driver' => 'carrier-pigeon']);
    }

    public function testMailmanRejectsAResolverThatReturnsTheWrongType(): void
    {
        MailManager::extendMailer('broken', fn () => new \stdClass());

        $this->expectException(MailmanException::class);
        MailManager::mailer(['driver' => 'broken']);
    }
}
