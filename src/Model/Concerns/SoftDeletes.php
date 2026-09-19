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
 * Soft deletion: hiding, including, restoring and filtering trashed rows.
 *
 * Part of Laika\Engine\Model; it relies on the model's properties and helpers
 * and is not meant to be used on its own.
 */
trait SoftDeletes
{
    /**
     * Include soft-deleted rows in the result.
     *
     * NOTE: this used to return *only* trashed rows, the opposite of the name.
     * onlyTrashed() is that behaviour.
     *
     * @return Static
     */
    public function withTrash(): Static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;
        return $this;
    }

    /**
     * Return only soft-deleted rows.
     * @return Static
     */
    public function onlyTrashed(): Static
    {
        $this->onlyTrashed = true;
        $this->withTrashed = false;
        return $this;
    }

    /**
     * Exclude soft-deleted rows. This is already the default on a soft-delete
     * model; it is here for models that opt in per chain with soft().
     * @return Static
     */
    public function withoutTrash(): Static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;
        return $this;
    }

    /**
     * Enable Soft Delete
     * @param bool $enable Default is true
     * @return Static
     */
    public function soft(bool $enable = true): Static
    {
        $this->softDelete = $enable;
        return $this;
    }

    /**
     * Restore Row(s)
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the restore operation
     * @return int Returns the number of affected rows
     */
    public function restore(): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new ModelException("No WHERE Clause provided for Restore operation.");
        }

        return $this->update([$this->deletedAtColumn => null]);
    }

    /**
     * Add the soft-delete predicate to a read.
     *
     * Only applies to a soft-delete model. Previously $softDelete affected
     * delete() alone, so reads returned trashed rows by default.
     */
    protected function applyTrashFilter(): void
    {
        if ($this->onlyTrashed) {
            $this->addWhere($this->sanitize($this->deletedAtColumn) . ' IS NOT NULL');
            return;
        }

        if ($this->softDelete && !$this->withTrashed) {
            $this->addWhere($this->sanitize($this->deletedAtColumn) . ' IS NULL');
        }
    }
}
