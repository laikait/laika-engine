<?php

declare(strict_types=1);

namespace Laika\Engine\Core\Tests\Unit;

use Laika\Engine\Route\Url;
use Laika\Engine\Route\Handler;
use Laika\Engine\Services\Date;
use PHPUnit\Framework\TestCase;

final class DemoTest extends TestCase
{
    public function testRouter()
    {
        Url::get('/', function () {
            return 'Hello, World!';
        })->name('home');
        $path = Handler::namedUrl('home');
        $this->assertNotNull($path ?: null, "Failed to Initialize Router or Generate URL");
    }

    public function testDate(): void
    {
        $this->assertIsInt(Date::getTimeStamp(), "Failed to Initialize Date or Get Timestamp");
    }
}
