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

use Laika\Engine\Model\Log;

/**
 * Limits, pages and walks a result set in pieces.
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait Paginates
{
    /**
     * Limit Clause
     * @param int|string $limit Required limit
     * @return Static
     */
    public function limit(int|string $limit): Static
    {
        $this->limit = (int) $limit;
        return $this;
    }

    /**
     * Offset Clause
     * @param int|string $page Page Number. Default is Page Number 1
     * @return Static
     */
    public function page(int|string $page = 1): Static
    {
        $this->page = max(1, (int) $page);
        return $this;
    }

    /**
     * Stream the result one row at a time.
     *
     * Use this instead of get() for result sets too large to materialise. The
     * finally runs on GeneratorExit too, so abandoning the loop early still
     * resets the builder.
     *
     * @return \Generator<int,array|object>
     */
    public function cursor(): \Generator
    {
        $sql = $this->build();
        Log::add($sql, $this->connection);

        $bindings = $this->bindings;
        $stmt     = null;

        try {
            $stmt = $this->run($sql, $bindings);

            while (($row = $stmt->fetch()) !== false) {
                yield $this->cast($row);
            }
        } finally {
            $stmt?->closeCursor();
            $this->reset();
        }
    }

    /**
     * Chunk the Results
     *
     * Pages with LIMIT/OFFSET, which is O(n^2) on large tables and can skip or
     * repeat rows if the callback mutates the set. Prefer cursor() unless you
     * specifically need batches.
     *
     * @param int $size Chunk Size. Example: 100
     * @param callable $callback Receives each batch. Return false to stop.
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
        $size = max(1, $size);

        // A caller's limit() is a cap on the total, not per batch.
        $remaining = $this->limit;

        $wheres   = $this->wheres;
        $bindings = $this->bindings;
        $havingB  = $this->havingBindings;
        $page     = 1;

        try {
            while (true) {
                $take = $remaining === null ? $size : min($size, $remaining);

                if ($take <= 0) {
                    break;
                }

                // build() consumes the where state, so restore it each round.
                $this->wheres         = $wheres;
                $this->bindings       = $bindings;
                $this->havingBindings = $havingB;
                $this->limit          = $take;
                $this->page           = $page;

                $sql = $this->build();
                Log::add($sql, $this->connection);

                $stmt = $this->run($sql, $this->bindings);

                $rows = $stmt->fetchAll();
                $stmt->closeCursor();

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $k => $row) {
                    $rows[$k] = $this->cast($row);
                }

                if ($callback($rows) === false) {
                    break;
                }

                if ($remaining !== null) {
                    $remaining -= count($rows);
                }

                if (count($rows) < $take) {
                    break;
                }

                $page++;
            }
        } finally {
            $this->reset();
        }
    }
}
