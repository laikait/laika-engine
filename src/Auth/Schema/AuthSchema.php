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

// Namespace
namespace Laika\Engine\Auth\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Engine\Model\Schema\Schema;
use Laika\Engine\Model\Schema\Blueprint;
use Laika\Engine\Model\Contract\SchemaAbstract;

class AuthSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'auth_tokens';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId();
            $t->bigInteger('user_id')->unsigned();
            $t->string('guard', 50);
            $t->string('browser', 50)->nullable()->default(null);
            $t->string('ip', 50)->nullable()->default(null);
            $t->string('user_agent')->nullable()->default(null);
            $t->string('token');
            $t->string('refresh_token')->nullable()->default(null);
            $t->timestamp('expires_at')->nullable()->default(null);
            $t->timestamp('revoked_at')->nullable()->default(null);
            $t->timestamp('created_at');
            
            // Indexes
            $t->index(['user_id', 'guard'], 'user_guard');
            $t->unique('token');
        });
    }
}
