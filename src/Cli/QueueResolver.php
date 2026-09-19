<?php

declare(strict_types=1);

namespace Laika\Engine\Cli;

use Laika\Engine\Core\Worker\Queue;
use Laika\Engine\Queue\Interfaces\FailedJobProviderInterface;
use Laika\Engine\Queue\Interfaces\QueueDriverInterface;

/**
 * Resolves the queue driver / failed-job provider from lf-config/queue.php
 * for the queue:* CLI commands (retry, failed, flush).
 *
 * The selection itself lives in Laika\Engine\Core\Worker\Queue, which bin/worker
 * and the web app use too; this is a thin alias that keeps the CLI's
 * historical default of 'database'.
 */
class QueueResolver
{
    public static function driver(): QueueDriverInterface
    {
        return Queue::driver('database');
    }

    public static function failedProvider(): FailedJobProviderInterface
    {
        return Queue::failedProvider('database');
    }
}
