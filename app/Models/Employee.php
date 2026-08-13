<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\PayType;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Employee extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'entity_id',
        'operating_unit_id',
        'employer_entity_id',
        'job_title',
        'labor_role',
        'pay_type',
        'monthly_salary',
        'hourly_rate',
        'hire_date',
        'status',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'status' => EmployeeStatus::class,
            'monthly_salary' => 'decimal:4',
            'hourly_rate' => 'decimal:4',
            'hire_date' => 'date',
            'record_version' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function employerEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'employer_entity_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(LaborLog::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
