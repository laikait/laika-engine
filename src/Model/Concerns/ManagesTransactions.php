<?php
/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);


namespace Laika\Engine\Model\Concerns;

use Laika\Engine\Model\Connection;

/**
 * Runs a callback inside a database transaction.
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait ManagesTransactions
{
    /**
     * Run a Transactional Callback
     *
     * Nested calls on the same connection are supported via savepoints: only
     * the outermost call opens a real transaction, and an inner failure rolls
     * back to its own savepoint rather than discarding the outer transaction.
     *
     * @param callable $callback Callback Function. Use Model as Argument. Example: function(Model $model) { ... }
     * @return mixed Returns the result of the callback
     * @throws \Throwable Whatever the callback threw, unwrapped.
     */
    public function transaction(callable $callback): mixed
    {
        // Make sure a stale handle is refreshed before the transaction opens —
        // reconnecting mid-transaction would silently discard it.
        $this->pdo();

        Connection::beginTransaction($this->connection);

        try {
            $result = $callback($this);
            Connection::commit($this->connection);
            return $result;
        } catch (\Throwable $e) {
            try {
                Connection::rollBack($this->connection);
            } catch (\Throwable $rollbackError) {
                // Connection already gone — the original failure is the one
                // worth reporting, so swallow this and fall through.
            }

            // Rethrow as-is. Wrapping destroyed PDOException::$errorInfo and the
            // SQLSTATE, and turned a domain exception thrown to abort the
            // transaction into an unrecognisable RuntimeException.
            throw $e;
        }
    }
}
