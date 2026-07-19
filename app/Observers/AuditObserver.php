<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLog;
use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditObserver
{
    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        $this->log($model, 'create', null, $model->getAttributes());
    }

    /**
     * Handle the Model "updated" event.
     */
    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        $old = [];
        $new = [];

        foreach ($dirty as $key => $value) {
            if (in_array($key, ['updated_at', 'created_at', 'record_version'])) {
                continue;
            }
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $value;
        }

        if (count($new) > 0) {
            $this->log($model, 'update', $old, $new);
        }
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        $this->log($model, 'delete', $model->getAttributes(), null);
    }

    /**
     * Persist the audit log entry.
     */
    private function log(Model $model, string $action, ?array $old, ?array $new): void
    {
        if ($model instanceof AuditLog) {
            return;
        }

        $context = app(CurrentUnitContext::class);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'operating_unit_id' => $context->id(),
            'table_name' => $model->getTable(),
            'record_id' => $model->getKey(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}
