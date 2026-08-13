<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveType;
use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeaveRequest extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids;

    protected $fillable = [
        'employee_id',
        'operating_unit_id',
        'start_date',
        'end_date',
        'leave_type',
        'reason',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
    ];

    protected $casts = [
        'leave_type' => LeaveType::class,
        'status' => LeaveRequestStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'decided_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
