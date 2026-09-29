<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OperatingUnit extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'blueprint_id',
        'name',
        'unit_type',
        'currency',
        'status',
        'manager_user_id',
        'revenue_account_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(UnitBlueprint::class, 'blueprint_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * The unit's own sales revenue account; null falls back to the 41 header.
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    /**
     * The cutter plant is known by its blueprint's workflows, not unit_type —
     * cutter, foam and furniture are all 'manufactory' (same rule as
     * OverheadService::wipAccountFor).
     */
    public function isCutter(): bool
    {
        return in_array('cutter_work_order', array_keys($this->blueprint?->workflow_set ?? []), true);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
