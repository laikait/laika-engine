<?php

declare(strict_types=1);

namespace Laika\Engine\Tests\Fixtures\Resource\Widget;

use Laika\Engine\Tests\Fixtures\Resource\WidgetInterface;

class GoodWidget implements WidgetInterface
{
    public function name(): string
    {
        return 'good';
    }
}
