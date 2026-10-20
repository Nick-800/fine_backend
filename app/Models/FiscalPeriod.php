<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar month of a fiscal year (ACC-11). Looked up by calendar year
 * and month, never by comparing dates, so midnight-timestamp storage of date
 * columns can't put an entry in the wrong month.
 */
final class FiscalPeriod extends Model
{
    use Auditable, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'period_number',
        'calendar_year',
        'calendar_month',
        'start_date',
        'end_date',
        'status',
        'locked_at',
        'locked_by_user_id',
        'reopened_at',
        'reopened_by_user_id',
        'reopen_reason',
        'record_version',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => FiscalPeriodStatus::class,
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
        'period_number' => 'integer',
        'calendar_year' => 'integer',
        'calendar_month' => 'integer',
        'record_version' => 'integer',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    /**
     * 'YYYY-MM', as shown in refusals.
     */
    public function label(): string
    {
        return sprintf('%04d-%02d', $this->calendar_year, $this->calendar_month);
    }
}
