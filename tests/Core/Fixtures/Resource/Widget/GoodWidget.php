<?php

declare(strict_types=1);

namespace Laika\Engine\Core\Tests\Fixtures\Resource\Widget;

use Laika\Engine\Core\Tests\Fixtures\Resource\WidgetInterface;

class GoodWidget implements WidgetInterface
{
    public function name(): string
    {
        return 'good';
    }
}
