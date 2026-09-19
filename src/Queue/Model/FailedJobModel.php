<?php

namespace Laika\Engine\Queue\Model;

use Laika\Engine\Model\Model;

/**
 * Model for the failed-jobs table. FailedJobModelSchema creates it.
 */
class FailedJobModel extends Model
{
    /** @var string Table Name */
    protected string $table = 'laika_failed_jobs';

    /** @var string Primary Column Name */
    protected string $id = 'id';

    /** @var string Database Connection Name */
    protected string $connection = 'default';
}
