<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OverheadCategory;
use App\Enums\OverheadExpenseStatus;
use App\Enums\OverheadPaymentSource;
use App\Models\Scopes\OperatingUnitOrSharedScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OverheadExpense extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'operating_unit_id',
        'category',
        'description',
        'amount',
        'currency',
        'expense_date',
        'payment_source',
        'status',
        'allocated_at',
        'record_version',
    ];

    protected $casts = [
        'category' => OverheadCategory::class,
        'status' => OverheadExpenseStatus::class,
        'payment_source' => OverheadPaymentSource::class,
        'amount' => 'decimal:4',
        'expense_date' => 'date',
        'allocated_at' => 'datetime',
        'record_version' => 'integer',
    ];

    /**
     * Null unit means "company-level, awaiting allocation" — those rows must
     * stay visible to unit-scoped callers, same as shared item categories.
     * The unit is always set explicitly by OverheadService, never inferred,
     * because the desktop shell pins a unit context even for the owner.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(new OperatingUnitOrSharedScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OverheadAllocation::class);
    }
}
