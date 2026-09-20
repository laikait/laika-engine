<?php

declare(strict_types=1);

namespace Laika\Engine\Tests\Fixtures\Relay;

/**
 * Lives in a relays directory but does not extend RelayProvider, which is what
 * resource:list reports and RelayBootstrap skips.
 */
class NotAProvider
{
}
