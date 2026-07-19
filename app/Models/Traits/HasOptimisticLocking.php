<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Exceptions\OptimisticLockConflictException;
use Illuminate\Database\Eloquent\Builder;

trait HasOptimisticLocking
{
    /**
     * Boot the trait to set initial versions and validate concurrency.
     */
    public static function bootHasOptimisticLocking(): void
    {
        static::saving(function ($model): void {
            if ($model->exists) {
                $clientVersion = $model->record_version;
                $originalVersion = $model->getOriginal('record_version');

                // Catch client-side version mismatches early
                if ($clientVersion !== null && (int) $clientVersion !== (int) $originalVersion) {
                    throw new OptimisticLockConflictException;
                }

                // Increment version for database write
                $model->record_version = ((int) $originalVersion) + 1;
            } else {
                // Default initial version
                $model->record_version = 1;
            }
        });
    }

    /**
     * Override performUpdate to add optimistic locking version check and fire events.
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $originalVersion = $this->getOriginal('record_version');

            if ($originalVersion !== null) {
                $query->where('record_version', $originalVersion);
            }

            $affected = $this->setKeysForSaveQuery($query)->update($dirty);

            if ($affected === 0) {
                throw new OptimisticLockConflictException;
            }

            $this->fireModelEvent('updated', false);

            $this->syncChanges();
        }

        return true;
    }
}
