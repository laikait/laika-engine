<?php
/**
 * Laika Auth
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Engine\Auth\Exceptions;

use Exception;
use Throwable;

class AuthException extends Exception
{
    public function __construct(string $message = "", int $code = 500, Throwable|null $previous = null)
    {
        return parent::__construct($message, $code, $previous);
    }
}