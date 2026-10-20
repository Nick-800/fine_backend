<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fiscal year of twelve calendar months (FR-GL-044). Years are created on
 * demand the first time something is dated inside them.
 */
final class FiscalYear extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by_user_id',
        'closing_entry_id',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'record_version',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => FiscalYearStatus::class,
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('period_number');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
