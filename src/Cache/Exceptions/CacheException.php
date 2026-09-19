<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Cache\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised for a misconfigured cache -- an unknown driver name, a missing
 * extension, an unwritable directory.
 *
 * Never raised for a miss, and never for a backend that is merely unreachable:
 * a cache that throws when the server is down takes the application down with
 * it, so those degrade to a miss instead.
 */
class CacheException extends RuntimeException
{
    public function __construct(string $message, int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: 500;
    }
}
