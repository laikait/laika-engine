<?php

declare(strict_types=1);

namespace Laika\Engine\Tests\Fixtures\Relay;

use Laika\Engine\Relay\RelayProvider;

class SampleProvider extends RelayProvider
{
    public function register(): void
    {
        $this->registry->instance('fixture.sample', new \stdClass());
    }
}
