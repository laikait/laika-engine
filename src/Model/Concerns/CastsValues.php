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

use Laika\Engine\Model\Exceptions\ModelException;

/**
 * Casts fetched rows and reads or writes a column on either row shape (array or object).
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait CastsValues
{
    /**
     * Apply type casts to a fetched row.
     * @return array|object
     */
    protected function cast(array|object $row): array|object
    {
        foreach ($this->casts as $column => $type) {
            if (!$this->rowHas($row, $column)) continue;

            $value = $this->rowGet($row, $column);

            // NULL means "absent" and must survive the cast. int/float/bool
            // used to coerce it to 0/0.0/false, losing the distinction.
            if ($value === null) {
                continue;
            }

            $row = $this->rowSet($row, $column, match (strtolower($type)) {
                'int', 'integer'  => $this->castInt($value),
                'float', 'double' => (float) $value,

                // DECIMAL comes back as a string from every driver; routing it
                // through float would introduce binary rounding error.
                'decimal'         => (string) $value,

                // Compare against known falsy values instead of a naive (bool)
                // cast. 't'/'f' are what pdo_pgsql returns for a boolean.
                'bool', 'boolean' => !in_array(
                    is_string($value) ? strtolower($value) : $value,
                    [0, 0.0, '0', '', 'false', 'f', 'off', 'no', false],
                    true
                ),

                'array', 'json'   => (static function () use ($value) {
                        $decoded = json_decode((string) $value, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new ModelException(
                                "Failed to decode JSON: " . json_last_error_msg()
                            );
                        }
                        return $decoded;
                    })(),

                'serialize' => (static function () use ($value) {
                        // No object instantiation from database content.
                        $result = unserialize((string) $value, ['allowed_classes' => false]);
                        if ($result === false && (string) $value !== 'b:0;') {
                            throw new ModelException(
                                "Failed to unserialize value: [{$value}]"
                            );
                        }
                        return $result;
                    })(),

                'string'          => (string) $value,

                // Falling through silently made a typo like 'integar' invisible.
                default           => throw new ModelException(
                    "Unknown cast type [{$type}] for column [{$column}]."
                ),
            });
        }

        return $row;
    }

    /**
     * Cast to int without silently clamping.
     *
     * A BIGINT UNSIGNED past PHP_INT_MAX (a snowflake id, say) would come back
     * as PHP_INT_MAX. Keeping it as a string is lossless.
     */
    protected function castInt(mixed $value): int|string
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $asInt = (int) $value;

            if ((string) $asInt !== ltrim($value, '+')) {
                return $value;
            }

            return $asInt;
        }

        return (int) $value;
    }

    /**
     * Read a column from a fetched row.
     *
     * Rows are arrays under PDO::FETCH_ASSOC and stdClass under FETCH_OBJ, and
     * callers may set either through the connection's `options`. These three
     * helpers are the only places that care which.
     */
    protected function rowGet(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    protected function rowHas(array|object $row, string $key): bool
    {
        return is_array($row) ? array_key_exists($key, $row) : property_exists($row, $key);
    }

    protected function rowSet(array|object $row, string $key, mixed $value): array|object
    {
        if (is_array($row)) {
            $row[$key] = $value;
        } else {
            $row->{$key} = $value;
        }

        return $row;
    }
}
