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

namespace Laika\Engine\Model;

use Laika\Engine\Model\Schema\Expression;
use Laika\Engine\Model\Exceptions\ModelException;
use Laika\Engine\Model\Exceptions\ConnectionException;
use Laika\Engine\Core\Support\Macroable;
use Laika\Engine\Model\Concerns\BuildsQueries;
use Laika\Engine\Model\Concerns\Paginates;
use Laika\Engine\Model\Concerns\SoftDeletes;
use Laika\Engine\Model\Concerns\CastsValues;
use Laika\Engine\Model\Concerns\CachesQueries;
use Laika\Engine\Model\Concerns\ManagesTransactions;

class Model
{
    use Macroable;
    use BuildsQueries, Paginates, SoftDeletes, CastsValues, CachesQueries, ManagesTransactions;

    /**
     * @var \PDO PDO Database Connection Object.
     * Do not read directly — use pdo(), which refreshes a stale handle.
     */
    protected \PDO $pdo;

    /** @var string Canonical database driver (mysql, sqlite, pgsql, sqlsrv, oci, firebird). */
    protected string $driver;

    /** @var int Connection registry generation this instance's $pdo came from */
    private int $generation = -1;

    /** @var string Selected Columns */
    protected string $columns = '*';

    /** @var array Join Clauses */
    protected array $joins = [];

    /** @var string[] Tables joined into the current query, unquoted, for cache invalidation */
    protected array $joinTables = [];

    /** @var bool Whether remember() was called for the current query */
    protected bool $remember = false;

    /** @var ?int TTL passed to remember(); null uses the configured default */
    protected ?int $rememberTtl = null;

    /**
     * @var ?callable Returns the store query results are cached in, or null.
     * A resolver rather than the store itself, so nothing is built or connected
     * until a query actually asks to be remembered.
     */
    private static $queryCache = null;

    /** @var int Seconds a remembered result is kept when remember() is given none */
    private static int $queryCacheTtl = 60;

    /** @var ?object Returned by the store only on a miss; compared by identity */
    private static ?object $miss = null;

    /** @var array Where Clauses */
    protected array $wheres = [];

    /** @var array Query Bindings */
    protected array $bindings = [];

    /** @var array $groupBy Group By Clauses */
    protected array $groupBy = [];

    /** @var array $orderBy Order By Clauses */
    protected array $orderBy = [];

    /** @var ?int $limit Limit Clause */
    protected ?int $limit = null;

    /** @var array $having Having Clauses */
    protected array $having = [];

    /**
     * @var array HAVING bindings, kept apart from $bindings.
     *
     * build() emits HAVING after WHERE, so mixing the two lists desynchronised
     * the placeholders whenever having() was called before where().
     */
    protected array $havingBindings = [];

    /** @var string $connection Database Connection Name */
    protected string $connection = 'default';

    /** @var string $table Table Name */
    protected string $table;

    /** @var string $id ID Column Name */
    protected string $id = 'id';

    /** @var string $uid UID Column Name */
    protected string $uid = 'uid';

    /** @var bool $softDelete Whether this model soft-deletes and hides trashed rows. */
    protected bool $softDelete = false;

    /**
     * @var bool The value $softDelete was declared with.
     *
     * $softDelete doubles as per-chain state (soft()), so reset() has to put
     * back what the subclass declared rather than a hardcoded false — otherwise
     * a soft-delete model starts hard-deleting on its second query.
     */
    protected bool $softDeleteDefault = false;

    /** @var bool Include soft-deleted rows in reads (withTrash()). */
    protected bool $withTrashed = false;

    /** @var bool Return only soft-deleted rows (onlyTrashed()). */
    protected bool $onlyTrashed = false;

    /** @var string $deletedAtColumn */
    protected string $deletedAtColumn = 'deleted_at';

    /** @var array<string,string> Casts. Example: ['column1' => 'int', 'column2' => 'string', [.....]] */
    protected array $casts = [];

    /** @var ?int $page Page Number */
    protected ?int $page = null;

    ####################################################################
    /*------------------------- EXTERNAL API -------------------------*/
    ####################################################################

    public function __construct(?string $connection = null)
    {
        // Set Connection Name. With no explicit choice here and none declared
        // on the subclass, honour Connection::setDefault().
        if (!empty($connection)) {
            $this->connection = $connection;
        } elseif ($this->connection === 'default') {
            $this->connection = Connection::getDefault();
        }

        // Init DB for Connection
        // if (class_exists("\\Laika\\Engine\\Services\\Init")) \Laika\Engine\Services\Init::db($this->connection);

        // Remember what the subclass declared so reset() can restore it.
        $this->softDeleteDefault = $this->softDelete;

        $this->refreshConnection();
    }

    /**
     * Get PDO Object
     *
     * Re-resolves from the Connection registry when the registry has changed
     * since this instance was built (Connection::add()/close()/reconnect()
     * invalidate live handles), so a Model never runs against a dead socket.
     *
     * @return \PDO
     */
    public function pdo(): \PDO
    {
        if ($this->generation !== Connection::generation()) {
            $this->refreshConnection();
        }

        return $this->pdo;
    }

    /**
     * Get the canonical driver name for this model's connection.
     */
    public function driver(): string
    {
        if ($this->generation !== Connection::generation()) {
            $this->refreshConnection();
        }

        return $this->driver;
    }

    /**
     * Pull a fresh PDO handle and driver name from the registry.
     */
    protected function refreshConnection(): void
    {
        // Add Connection if doesn't exists
        if (!Connection::has($this->connection)) {
            $config = config('database', $this->connection);
            if ($config === null) {
                throw new ConnectionException("Connection [{$this->connection}] is not configured!");
            }
            // Register under this model's name. Without it the config lands on
            // the default name, overwriting 'default', and get() below throws.
            Connection::add($config, $this->connection);
        }

        $this->pdo        = Connection::get($this->connection);
        $this->driver     = Connection::driver($this->connection);
        $this->generation = Connection::generation();
    }

    /**
     * Get Result
     *
     * Rows come back as arrays or stdClass depending on the connection's
     * PDO::ATTR_DEFAULT_FETCH_MODE; both are supported.
     *
     * @return array<int,array|object>
     */
    public function get(): array
    {
        $sql = $this->build();

        try {
            // The key is taken after build(), which folds the HAVING bindings in
            // and appends the soft-delete predicate: taken earlier it would miss
            // both and two different queries could share one entry.
            $key = $this->queryCacheKey('get', $sql);

            if ($key !== null) {
                $cached = $this->queryCacheRead($key);

                if (is_array($cached)) {
                    // Raw rows are cached, not cast ones, so the model's casts
                    // apply on a hit exactly as on a miss
                    return array_map(fn (array $row) => $this->cast($cached['objects'] ? (object) $row : $row), $cached['rows']);
                }
            }

            // Logged only when the database is actually asked
            Log::add($sql, $this->connection);

            $stmt = $this->run($sql, $this->bindings);

            $rows = $stmt->fetchAll();

            if ($key !== null) {
                // Stored as arrays: under FETCH_OBJ a row is a stdClass, which
                // the cache deliberately refuses to rebuild from serialized data
                $this->queryCacheWrite($key, [
                    'objects' => isset($rows[0]) && is_object($rows[0]),
                    'rows'    => array_map(static fn ($row) => (array) $row, $rows),
                ]);
            }

            foreach ($rows as $k => $row) {
                $rows[$k] = $this->cast($row);
            }

            return $rows;
        } finally {
            // Without this, any failure above left the wheres and bindings
            // attached to the instance and they leaked into the next query.
            $this->reset();
        }
    }

    /**
     * Get First Result
     * @return array|object|null The row, or null when nothing matched.
     */
    public function first(): array|object|null
    {
        if (empty($this->wheres)) {
            throw new ModelException("WHERE Clause Required For Single Data.");
        }

        $this->limit(1);
        $result = $this->get();

        return $result[0] ?? null;
    }

    /**
     * Find By ID / UID
     * @param int|string $id ID / UID
     * @return array|object|null The row, or null when nothing matched.
     */
    public function find(int|string $id): array|object|null
    {
        return is_numeric($id)
            ? $this->where([$this->id => (int) $id])->first()
            : $this->where([$this->uid => (string) $id])->first();
    }

    /**
     * Count Rows
     *
     * COUNT is built independently of select() — deriving it from $columns made
     * count() a syntax error after select([...]) or distinct().
     *
     * @return int
     */
    public function count(): int
    {
        $distinct = str_starts_with($this->columns, 'DISTINCT ');

        if ($distinct) {
            $expr = trim(substr($this->columns, strlen('DISTINCT ')));
            $this->columns = $expr === '*' ? 'COUNT(*)' : "COUNT(DISTINCT {$expr})";
        } else {
            $this->columns = 'COUNT(*)';
        }

        $this->columns .= ' AS ' . $this->sanitize('aggregate');

        // An aggregate over a page is not a count of the table.
        $this->limit = null;
        $this->page  = null;

        $sql = $this->build();

        try {
            $key = $this->queryCacheKey('count', $sql);

            if ($key !== null) {
                $cached = $this->queryCacheRead($key);

                if (is_int($cached)) {
                    return $cached;
                }
            }

            Log::add($sql, $this->connection);

            $stmt   = $this->run($sql, $this->bindings);
            $result = $stmt->fetch();
            $count  = $result === false ? 0 : (int) $this->rowGet($result, 'aggregate');

            if ($key !== null) {
                $this->queryCacheWrite($key, $count);
            }

            return $count;
        } finally {
            $this->reset();
        }
    }

    /**
     * Get Single Column Values as Array
     *
     * The result key is the alias or the bare column, never the qualified name,
     * so pluck('users.email') reads back as 'email'.
     *
     * @param string $column Column Name
     * @return array
     */
    public function pluck(string $column): array
    {
        $key = $column;

        if (preg_match('/\s+AS\s+(\S+)$/i', $column, $m)) {
            $key = $m[1];
        } elseif (str_contains($column, '.')) {
            $key = substr($column, strrpos($column, '.') + 1);
        }

        $rows = $this->select($column)->get();

        return array_map(fn (array|object $row): mixed => $this->rowGet($row, $key), $rows);
    }

    /**
     * Check if records exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * First Or Create
     *
     * NOTE: this is three statements without a transaction, so two concurrent
     * callers can both miss and both insert. Wrap it in transaction() when that
     * matters.
     *
     * @param array $where Columns to search for existing record
     * @param array $data Additional data to insert if not found
     * @return array|object|null Found or newly created record
     */
    public function firstOrCreate(array $where, array $data = []): array|object|null
    {
        $row = $this->where($where)->first();

        if ($row !== null) {
            return $row;
        }

        $this->insert(array_merge($where, $data));

        return $this->where($where)->first();
    }

    /**
     * Get First or Fail
     * @throws ModelException When no record matches.
     * @return array|object
     */
    public function firstOrFail(): array|object
    {
        $row = $this->first();

        if ($row === null) {
            throw new ModelException("No Records Found");
        }

        return $row;
    }

    /**
     * Insert Row('s)
     *
     * Oracle and Firebird reject multi-row VALUES, so on those drivers the rows
     * are sent one statement at a time instead of batched. They also have no
     * bare lastInsertId() — this returns '' there, and the caller must read the
     * sequence/generator itself.
     *
     * The returned id is the last row's on pgsql, which reads it back through
     * RETURNING, and the first of the final statement on mysql, which reports
     * LAST_INSERT_ID(). Either is '' when the driver cannot name one.
     *
     * @param array{} $data Insert Row('s) Data. Example: ['name' => 'John', 'age' => 30] or [0 => ['name' => 'John'], ['name' => 'Doe']]
     * @throws ModelException|\PDOException
     * @return string|false Returns the last inserted ID
     */
    public function insert(array $data): string|false
    {
        if (empty($data)) {
            throw new ModelException('Cannot Insert Empty Rows.');
        }

        // Normalize input: detect single row vs multiple rows. array_is_list()
        // rather than isset($data[0]) — rows out of array_filter() are keyed
        // 1,2,3 and used to be misread as a single row.
        $isMultiple = array_is_list($data) && isset($data[0]) && is_array($data[0]);

        $rows = $isMultiple ? array_values($data) : [$data];

        // Extract columns from first row
        $keys = array_keys($rows[0]);

        // Validate every row before executing anything. This used to happen
        // inside the chunk loop, so a bad row at index 1500 left the first
        // chunk already committed.
        foreach ($rows as $i => $row) {
            if (array_keys($row) !== $keys) {
                throw new ModelException(
                    "All Insert Rows Must Have Identical Columns (row {$i} differs)."
                );
            }
        }

        // Quote columns
        $columns = array_map(function ($column) {
            return $this->sanitize($column);
        }, $keys);

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        $driver = $this->driver();

        // Oracle needs INSERT ALL and Firebird needs EXECUTE BLOCK to batch;
        // rather than carry two more dialects, send those one row per statement.
        //
        // Everywhere else the real ceiling is the driver's placeholder limit and
        // not a row count, so it has to be divided by the width of a row: pgsql
        // and mysql carry the parameter count of a prepared statement in an
        // int16, sqlite defaults to half that, and SQL Server stops at 2100. A
        // flat 1000 rows overflowed all four on a wide enough table.
        $maxPlaceholders = match ($driver) {
            'sqlsrv' => 2100,
            'sqlite' => 32766,
            default  => 65535,
        };

        $rowsPerStatement = in_array($driver, ['oci', 'firebird'], true)
            ? 1
            : max(1, min(1000, intdiv($maxPlaceholders, max(1, count($columns)))));

        $chunks = array_chunk($rows, $rowsPerStatement);

        // pdo_pgsql's bare lastInsertId() is SELECT lastval(), which is scoped to
        // the session rather than to this table: on a table with no sequence it
        // hands back an id from an unrelated earlier insert, and an AFTER INSERT
        // trigger that burns another sequence shadows the row's own value. Both
        // are silent. RETURNING asks the statement itself instead.
        $returning = $driver === 'pgsql' ? $this->sanitize($this->id) : null;

        try {
            try {
                $lastId = $this->runInsert($tbl, $columns, $chunks, $returning);
            } catch (\PDOException $e) {
                // 42703 undefined_column — the model's $id is not on this table
                // (a join or log table). RETURNING is checked before anything is
                // written, so the batch is intact: send it again without one.
                // runInsert has already rolled back, so pgsql is not left with
                // an aborted transaction here.
                if ($returning === null || (string) $e->getCode() !== '42703') {
                    throw $e;
                }

                $returning = null;
                $lastId    = $this->runInsert($tbl, $columns, $chunks, null);
            }
        } finally {
            $this->reset();
        }

        $this->invalidateQueryCache($this->table);

        // RETURNING answered it, so lastval() is not consulted at all.
        if ($lastId !== null) {
            return $lastId;
        }

        // PDO_OCI and PDO_Firebird require the sequence/generator name here and
        // return '' or throw without one. pgsql only reaches this line when the
        // table had no id column to return, and falling through to its bare
        // lastInsertId() would resurrect the very bug RETURNING fixes: lastval()
        // would hand back whatever sequence the session touched last. Don't
        // pretend to have an id, and never turn a successful write into an
        // exception.
        if (in_array($driver, ['oci', 'firebird', 'pgsql'], true)) {
            return '';
        }

        try {
            return $this->pdo()->lastInsertId();
        } catch (\PDOException) {
            return '';
        }
    }

    /**
     * Update Clause
     * @param array $data Required data to update
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the update operation
     * @return int Returns the number of affected rows
     */
    public function update(array $data): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new ModelException("No WHERE Clause Provided for UPDATE operation.");
        }

        // Check Data Is Not Empty
        if (empty($data)) {
            throw new ModelException("'\$data' Parameter Should Not Be Empty!");
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $column = $this->sanitize($column);
            $set[] = "{$column} = ?";
        }

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        // Make SQL. The join goes before SET — appending it after produced
        // "UPDATE t SET a = ? LEFT JOIN ...", a syntax error on every driver.
        $sql = "UPDATE {$tbl}";

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        $sql .= " SET " . implode(', ', $set);

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' ', $this->wheres);
        }

        // Add Queries to Log
        Log::add($sql, $this->connection);

        try {
            $stmt = $this->run($sql, array_merge(array_values($data), $this->bindings));
            $this->invalidateQueryCache($this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
    }

    /**
     * Delete Row(s)
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the delete operation
     * @return int Returns the number of affected rows
     */
    public function delete(): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new ModelException("No WHERE Clause provided for DELETE operation.");
        }

        if ($this->softDelete) {
            return $this->update([$this->deletedAtColumn => date('Y-m-d H:i:s')]);
        }

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        // Make SQL
        $sql = "DELETE FROM {$tbl}";

        $sql .= " WHERE " . implode(' ', $this->wheres);

        // Add Queries to Log
        Log::add($sql, $this->connection);

        try {
            $stmt = $this->run($sql, $this->bindings);
            $this->invalidateQueryCache($this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
    }

    /**
     * Increment a numeric column by $number. Returns affected row count.
     * @param string $column Column to Increment
     * @param int $number Increment Number. default is 1
     * @example $users->increment('views', 1);
     */
    public function increment(string $column, int $number = 1): int
    {
        return $this->step($column, $number, '+', 'Increment');
    }

    /**
     * Decrement a numeric column by $number. Returns affected row count.
     * @param string $column Column to Decrement
     * @param int $number Decrement Number. default is 1
     * @example $users->decrement('views', 1);
     */
    public function decrement(string $column, int $number = 1): int
    {
        return $this->step($column, $number, '-', 'Decrement');
    }

    /**
     * Shared body for increment()/decrement().
     *
     * Identifier validation goes through sanitize() like everywhere else. The
     * old private regex /^[a-z._]+$/i rejected any column with a digit, so
     * views2 and q1_total were unusable.
     */
    protected function step(string $column, int $number, string $sign, string $label): int
    {
        if (str_contains($column, '.')) {
            [$tblName, $colName] = explode('.', $column, 2);
            $tbl = $this->sanitize($tblName);
            $col = $this->sanitize($colName);
        } else {
            $colName = $column;
            $tbl     = $this->sanitize($this->table);
            $col     = $this->sanitize($column);
        }

        if ($colName === $this->id) {
            throw new ModelException("Not Possible To {$label} Primary Key!");
        }

        if (empty($this->wheres)) {
            throw new ModelException("No WHERE Clause Provided For {$label} Operation.");
        }

        $where = "WHERE " . implode(' ', $this->wheres);
        $sql   = "UPDATE {$tbl} SET {$col} = {$col} {$sign} ? {$where}";

        Log::add($sql, $this->connection);

        try {
            $stmt = $this->run($sql, array_merge([$number], $this->bindings));
            $this->invalidateQueryCache(str_contains($column, '.') ? $tblName : $this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
    }

    /**
     * Execute Raw Query With Automatic Return Type Detection
     * @param string $sql Raw SQL query
     * @param ?array $bindings Parameter bindings
     * @return \PDOStatement Returns array of rows for SELECT, affected rows for INSERT/UPDATE/DELETE
     */
    public function execute(string $sql, ?array $bindings = null): \PDOStatement
    {
        Log::add($sql, $this->connection);

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($bindings);

            return $stmt;
        } finally {
            // This bypasses the builder entirely; anything already chained is
            // discarded rather than left dangling on the instance.
            $this->reset();
        }
    }

    /**
     * Debug SQL
     * @return string Returns the SQL query with bindings
     */
    public function debug(): string
    {
        // build() mutates: it folds the HAVING bindings into $bindings and adds
        // the soft-delete predicate. Since debug() no longer resets, that state
        // has to be put back or a following get() would apply both twice.
        $snapshot = [
            $this->wheres,
            $this->bindings,
            $this->havingBindings,
            $this->columns,
            $this->limit,
            $this->page,
        ];

        try {
            $sql      = $this->build();
            $bindings = $this->bindings;
        } finally {
            [
                $this->wheres,
                $this->bindings,
                $this->havingBindings,
                $this->columns,
                $this->limit,
                $this->page,
            ] = $snapshot;
        }

        $sql = preg_replace_callback('/\?/', function () use (&$bindings) {
            $value = array_shift($bindings);

            return match (true) {
                $value === null    => 'NULL',
                is_bool($value)    => $value ? 'TRUE' : 'FALSE',
                is_numeric($value) => (string) $value,
                is_array($value)   => "'" . addslashes(json_encode($value)) . "'",
                default            => "'" . addslashes((string) $value) . "'",
            };
        }, $sql);

        // Deliberately no reset() — inspecting a query used to destroy it, so
        // ->where(...)->debug() then ->get() ran without the where.
        return "{$sql};";
    }

    /**
     * Generate UID
     * @return string
     */
    public function uid(): string
    {        
        return implode('-', str_split(bin2hex(random_bytes(16)), 8));
    }

    ####################################################################
    /*------------------------- INTERNAL API -------------------------*/
    ####################################################################

    /**
     * Send the prepared chunks, optionally asking PostgreSQL for the id it
     * generated.
     *
     * The batch runs inside a transaction whenever a partial insert is possible
     * — more than one statement — and always when RETURNING is in play, so a
     * column name the table does not have can be rolled back and retried.
     * Connection::beginTransaction() opens a SAVEPOINT rather than a second
     * transaction when the caller already has one open, so this is safe to nest.
     *
     * @param string   $tbl       Already-quoted table name.
     * @param string[] $columns   Already-quoted column names.
     * @param array<int,array<int,array<string,mixed>>> $chunks Rows, batched.
     * @param ?string  $returning Already-quoted id column, or null for no RETURNING.
     * @throws \PDOException
     * @return ?string The id RETURNING produced, or null when it was not used.
     */
    protected function runInsert(string $tbl, array $columns, array $chunks, ?string $returning): ?string
    {
        $stmt        = null;
        $preparedSql = null;
        $lastId      = null;
        $lastChunk   = array_key_last($chunks);

        $wrap = count($chunks) > 1 || $returning !== null;

        if ($wrap) {
            Connection::beginTransaction($this->connection);
        }

        try {
            foreach ($chunks as $index => $chunk) {

                // Build placeholders for this chunk
                $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $placeholders    = implode(', ', array_fill(0, count($chunk), $rowPlaceholders));

                // Build SQL
                $sql = "INSERT INTO {$tbl} (" . implode(', ', $columns) . ") VALUES {$placeholders}";

                // Only the final statement carries RETURNING: the id reported is
                // the last row's, which is what lastval() used to hand back here.
                $wantsId = $returning !== null && $index === $lastChunk;

                if ($wantsId) {
                    $sql .= " RETURNING {$returning}";
                }

                // Add Queries to Log
                Log::add($sql, $this->connection);

                // Flatten bindings
                $bindings = [];
                foreach ($chunk as $row) {
                    $bindings = array_merge($bindings, array_values($row));
                }

                // Execute. Every full chunk produces identical SQL, so the handle is
                // reused rather than re-prepared — this matters most on oci/firebird,
                // where the chunk is a single row. The PDOException is left intact:
                // wrapping it discarded errorInfo and the SQLSTATE, so callers could
                // not tell a duplicate key from a dead connection.
                if ($sql !== $preparedSql) {
                    $stmt        = $this->pdo()->prepare($sql);
                    $preparedSql = $sql;
                }

                // bindAll(), not execute($bindings): the array form binds every
                // value as PARAM_STR, and (string) false is '', which pgsql
                // rejects outright for a BOOLEAN, INTEGER or DATE column.
                $this->bindAll($stmt, $bindings);

                $stmt->execute();

                if ($wantsId) {
                    $ids    = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
                    $lastId = $ids === [] ? null : (string) end($ids);
                }
            }

            if ($wrap) {
                Connection::commit($this->connection);
            }
        } catch (\Throwable $e) {
            if ($wrap) {
                try {
                    Connection::rollBack($this->connection);
                } catch (\Throwable) {
                    // The original failure is the one worth reporting.
                }
            }

            throw $e;
        }

        return $lastId;
    }

    /**
     * Bind a positional list onto a prepared statement with the PDO type each
     * PHP value actually implies.
     *
     * PDOStatement::execute($array) binds every value as PDO::PARAM_STR instead.
     * Column affinity usually hides that, but (string) false is '', which pgsql
     * and sqlsrv reject outright for a BOOLEAN/BIT, INTEGER or DATE column; and
     * a comparison with no column to convert against — HAVING against an
     * aggregate alias, say — compares an integer to a string and silently
     * matches nothing.
     */
    protected function bindAll(\PDOStatement $stmt, array $bindings): void
    {
        $i = 0;
        foreach ($bindings as $value) {
            $type = match (true) {
                $value === null => \PDO::PARAM_NULL,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_int($value)  => \PDO::PARAM_INT,
                default         => \PDO::PARAM_STR,
            };

            $stmt->bindValue(++$i, $value, $type);
        }
    }

    /**
     * Prepare, bind and execute.
     */
    protected function run(string $sql, array $bindings): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);

        $this->bindAll($stmt, $bindings);

        $stmt->execute();

        return $stmt;
    }

    /**
     * Reset Query
     * @return void
     */
    protected function reset(): void
    {
        $this->columns  =   '*';
        $this->joins    =   [];
        $this->joinTables = [];
        $this->remember =   false;
        $this->rememberTtl = null;
        $this->wheres   =   [];
        $this->bindings =   [];
        $this->groupBy  =   [];
        $this->orderBy  =   [];
        $this->limit    =   null;
        $this->page     =   null;
        $this->having   =   [];
        $this->havingBindings = [];
        $this->softDelete = $this->softDeleteDefault;
        $this->withTrashed = false;
        $this->onlyTrashed = false;
    }

    /**
     * Prevent Cloning
     * @throws \Exception Throws an exception if cloning is attempted
     */
    protected function __clone()
    {
        throw new ModelException('Cloning is Not Allowed.');
    }

    /**
     * Prevent Serialization
     * @throws \Exception Throws an exception if serialization is attempted
     */
    public function __wakeup()
    {
        throw new ModelException('Unserializing is Not Allowed.');
    }

    /**
     * Check if Property is Set
     * @param string $prop Property Name
     * @return bool
     */
    public function __isset($prop): bool
    {
        return isset($this->$prop);
    }

    /**
     * Get Property Value
     * @param string $prop Property Name
     * @return mixed
     */
    public function __get($prop): mixed
    {
        return $this->$prop;
    }
}
