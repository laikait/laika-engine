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

use Laika\Engine\Model\Schema\Expression;
use Laika\Engine\Model\Exceptions\ModelException;

/**
 * Builds the SELECT, JOIN, WHERE, GROUP BY, HAVING and ORDER BY parts of a query, and the SQL string itself.
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait BuildsQueries
{
    /**
     * // Table Name
     * @param string $table Required table name
     * @return Static
     */
    public function table(string $table): Static
    {
        // Deliberately does not reset(): calling table() mid-chain used to
        // discard the select and wheres already set, silently.
        $this->table = $table;
        return $this;
    }

    /**
     * Select
     * @param array|string|null $columns Column names. Default is null
     * @return Static
     */
    public function select(array|string|Expression|null $columns = null): Static
    {
        if ($columns === null || $columns === '' || $columns === []) {
            $this->columns = '*';
            return $this;
        }

        // A plain string may be a comma-separated list — "id, name, email".
        // Splitting is safe here because every part is sanitised below; the old
        // code split too, but then passed anything containing "(" through raw.
        $list = match (true) {
            is_array($columns)              => $columns,
            $columns instanceof Expression  => [$columns],
            default                         => explode(',', $columns),
        };

        $parts = array_map(function (mixed $col): string {
            // Raw SQL is opt-in and explicit. Anything else is an identifier.
            if ($col instanceof Expression) {
                return (string) $col;
            }

            if (!is_string($col)) {
                throw new ModelException('Select columns must be strings or Expression instances.');
            }

            $col = trim($col);

            // "expr AS alias" — both halves are identifiers here; use an
            // Expression for the left side when it needs to be a function call.
            if (preg_match('/^(.+?)\s+AS\s+(\S+)$/i', $col, $m)) {
                return $this->sanitize(trim($m[1])) . ' AS ' . $this->sanitize(trim($m[2]));
            }

            // "*" and "users.*" are wildcards, not identifiers.
            if ($col === '*') {
                return '*';
            }

            if (str_ends_with($col, '.*')) {
                return $this->sanitize(substr($col, 0, -2)) . '.*';
            }

            return $this->sanitize($col);
        }, $list);

        $parts = array_values(array_filter($parts, static fn (string $c): bool => $c !== ''));

        $this->columns = $parts === [] ? '*' : implode(', ', $parts);
        return $this;
    }

    /**
     * Select Distinct Rows
     * @return Static
     */
    public function distinct(): Static
    {
        $this->columns = 'DISTINCT ' . $this->columns;
        return $this;
    }

    /**
     * Join Clause
     * @param string $table Required table name to join
     * @param string $first Required first column
     * @param string $operator Required operator
     * @param string $second Required second column
     * @param string $type Optional join type (LEFT, RIGHT, INNER)
     * @return Static
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'LEFT'): Static
    {
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>='];
        if (!in_array(trim($operator), $allowedOps, true)) {
            throw new ModelException("Invalid join operator [{$operator}].");
        }

        $type = strtoupper($type);
        // Kept unquoted: a write to this table has to invalidate this query
        $this->joinTables[] = $table;
        // Quote String
        $table = $this->sanitize($table);
        $first = $this->sanitize($first);
        $second = $this->sanitize($second);

        if (!in_array($type, ['LEFT', 'RIGHT', 'INNER'])) {
            throw new ModelException("Invalid join type: {$type}");
        }

        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Where Clause
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $operator Optional operator (default: '=')
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function where(array $where, string $operator = '=', string $compare = 'AND'): Static
    {
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new ModelException("Invalid operator [{$operator}].");
        }

        if (empty($where)) {
            return $this;
        }

        $operator = strtoupper(trim($operator));

        $parts    = [];
        $bindings = [];

        foreach ($where as $col => $val) {
            $parts[]    = $this->sanitize((string) $col) . " {$operator} ?";
            $bindings[] = $val;
        }

        // One where() call is one group: the columns inside it are joined by
        // $compare, and the group as a whole attaches to the chain with the same
        // $compare. Without the parentheses a multi-column OR leaked into the
        // surrounding chain and SQL's AND-binds-tighter rule silently returned
        // the wrong rows.
        $glue      = strtoupper(trim($compare)) === 'OR' ? ' OR ' : ' AND ';
        $condition = count($parts) === 1 ? $parts[0] : '(' . implode($glue, $parts) . ')';

        $this->addWhere($condition, $bindings, $compare);
        return $this;
    }

    /**
     * Where Not Equal
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereNot(array $where, string $compare = 'AND'): Static
    {
        return $this->where($where, '!=', $compare);
    }

    /**
     * Where In
     * @param string $column Required column name
     * @param array $values Required array of values to match
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereIn(string $column, array $values, string $compare = 'AND'): Static
    {
        // Quote String
        $column = $this->sanitize($column);

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->addWhere("{$column} IN ({$placeholders})", $values, $compare);
        return $this;
    }

    /**
     * Where Not In
     * @param string $column Required column name
     * @param array $values Required array of values to match
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereNotIn(string $column, array $values, string $compare = 'AND'): Static
    {
        $column = $this->sanitize($column);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->addWhere("{$column} NOT IN ({$placeholders})", $values, $compare);
        return $this;
    }

    /**
     * Check Column is Null
     * @param string $column Required column name
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function isNull(string $column, string $compare = 'AND'): Static
    {
        // Quote String
        $column = $this->sanitize($column);

        $this->addWhere("{$column} IS NULL", [], $compare);
        return $this;
    }

    /**
     * Check Column is Not Null
     * @param string $column Required column name
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function notNull(string $column, string $compare = 'AND'): Static
    {
        // Quote String
        $column = $this->sanitize($column);

        $this->addWhere("{$column} IS NOT NULL", [], $compare);
        return $this;
    }

     /**
     * Between Clause
     * @param string $column Required column name
     * @param mixed $value1 Required first value
     * @param mixed $value2 Required second value
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function between(string $column, mixed $value1, mixed $value2, string $compare = 'AND'): Static
    {
        // Quote String
        $column = $this->sanitize($column);

        $this->addWhere("{$column} BETWEEN ? AND ?", [$value1, $value2], strtoupper($compare));
        return $this;
    }

    /**
     * Where Group
     * @param callable $callback Callback Function. Example: function(Model $model) {$model->where(...)}
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereGroup(callable $callback, string $compare = 'AND'): Static
    {
        $model = new Static($this->connection);

        $callback($model);

        if (empty($model->wheres)) {
            return $this;
        }

        $wheres = implode(' ', $model->wheres);
        $prefix = empty($this->wheres) ? '' : (strtoupper($compare) === 'OR' ? 'OR ' : 'AND ');
        $this->wheres[] = "{$prefix}({$wheres})";
        $this->bindings = array_merge($this->bindings, $model->bindings);

        return $this;
    }

    /**
     * Group By Clause
     * @param string ...$columns Required columns to group by
     * @return Static
     */
    public function groupBy(string ...$columns): Static
    {
        $this->groupBy = array_map(function($column){
            // Quote String
            return $this->sanitize($column);
        }, $columns);
        return $this;
    }

    /**
     * Having Clause
     * @param string $column Example: 'id'
     * @param string $operator Example: '='
     * @param mixed $value Example: 1
     * @return Static
     */
    public function having(string $column, string $operator, mixed $value): Static
    {
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new ModelException("Invalid operator [{$operator}].");
        }

        // Quote String
        $column = $this->sanitize($column);

        $this->having[]         = "{$column} " . strtoupper(trim($operator)) . " ?";
        $this->havingBindings[] = $value;
        return $this;
    }

    /**
     * Order By Clause
     * @param string $column Required column name
     * @param string $direction Optional direction (ASC, DESC)
     * @throws \InvalidArgumentException Throws an exception if an invalid direction is provided
     * @return Static
     */
    public function order(string $column, string $direction = 'ASC'): Static
    {
        $direction = strtoupper($direction);
        // Check Direction
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new ModelException("Invalid order direction: {$direction}");
        }
        // Quote String
        $column = $this->sanitize($column);

        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Add Where Condition
     * @param string $condition Required condition string
     * @param array $bindings Optional bindings for the condition
     * @param string $compare Optional comparison type (AND, OR)
     * @return void
     */
    protected function addWhere(string $condition, array $bindings = [], string $compare = 'AND'): void
    {
        $compare = strtoupper($compare);
        $prefix = empty($this->wheres) ? '' : ($compare === 'OR' ? 'OR ' : 'AND ');
        $this->wheres[] = "{$prefix}{$condition}";
        $this->bindings = array_merge($this->bindings, $bindings);
    }

    /**
     * Build the SQL Query
     * @throws \PDOException Throws an exception if the table name is not set
     * @return string Returns the built SQL query
     */
    protected function build(): string
    {
        if (empty($this->table)) {
            throw new ModelException("Table Name Not Found!");
        }

        // A soft-delete model hides trashed rows unless asked otherwise. This
        // has to happen before the WHERE is assembled below.
        $this->applyTrashFilter();

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        $sql = "SELECT {$this->columns} FROM {$tbl}";

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' ', $this->wheres);
        }

        if (!empty($this->groupBy)) {
            $sql .= " GROUP BY " . implode(', ', $this->groupBy);
        }

        if (!empty($this->having)) {
            $sql .= " HAVING " . implode(' AND ', $this->having);

            // HAVING is emitted after WHERE, so its bindings belong after the
            // WHERE bindings regardless of the order the methods were called in.
            $this->bindings = array_merge($this->bindings, $this->havingBindings);
            $this->havingBindings = [];
        }

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }

        $offset = null;

        if ($this->page !== null) {
            if ($this->limit === null) {
                throw new ModelException(
                    "limit() Must Be Set Before Using page()."
                );
            }
            $offset = ($this->page - 1) * $this->limit;
        }

        if ($this->limit !== null) {
            switch ($this->driver()) {
                case 'sqlsrv':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new ModelException(
                                "SQL Server Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        // T-SQL wants SELECT DISTINCT TOP n, not SELECT TOP n DISTINCT.
                        $sql = preg_replace(
                            '/^SELECT\s+(DISTINCT\s+)?/i',
                            "SELECT $1TOP {$this->limit} ",
                            $sql
                        );
                    }
                    break;

                case 'oci':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new ModelException(
                                "Oracle Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        $sql .= " FETCH FIRST {$this->limit} ROWS ONLY";
                    }
                    break;

                case 'firebird':
                    $start = ($offset ?? 0) + 1;
                    $end   = $start + $this->limit - 1;
                    $sql  .= " ROWS {$start} TO {$end}";
                    break;

                default:
                    $sql .= " LIMIT {$this->limit}";
                    if ($offset !== null) {
                        $sql .= " OFFSET {$offset}";
                    }
                    break;
            }
        }

        return $sql;
    }

    protected function wrapIdent(string $name, string $driver): string
    {
        return match ($driver) {
            'mysql'  => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv' => '[' . str_replace(']', ']]', $name) . ']',
            default  => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    /**
     * Add Table Sanitization in Model Class
     * @return string
     */
    protected function sanitize(string $identifier): string
    {
        // Remove dangerous characters
        // return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);

        // Handle table.column notation
        $driver = $this->driver();

        if (str_contains($identifier, '.')) {
            [$table, $column] = explode('.', $identifier, 2);
            return $this->wrapIdent($this->validate($table), $driver)
                . '.'
                . $this->wrapIdent($this->validate($column), $driver);
        }

        return $this->wrapIdent($this->validate($identifier), $driver);
    }

    protected function validate(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ModelException("Invalid Identifier [{$name}].");
        }
        return $name;
    }
}
