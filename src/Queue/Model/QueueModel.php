<?php

namespace Laika\Engine\Queue\Model;

use Laika\Engine\Model\Model;

/**
 * Model for the queue jobs table. QueueModelSchema creates it.
 */
class QueueModel extends Model
{
    /** @var string Table Name */
    protected string $table = 'laika_queue_jobs';

    /** @var string Primary Column Name */
    protected string $id = 'id';

    /** @var string Database Connection Name */
    protected string $connection = 'default';
}
