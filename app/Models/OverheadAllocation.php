<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationMethod;
use App\Enums\AllocationPaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OverheadAllocation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'overhead_expense_id',
        'operating_unit_id',
        'method',
        'amount',
        'absorbed',
        'status',
        'approved_by_user_id',
        'approved_at',
        'paid_by_user_id',
        'paid_at',
        'confirmation_note',
    ];

    protected $casts = [
        'method' => AllocationMethod::class,
        'amount' => 'decimal:4',
        'absorbed' => 'boolean',
        'status' => AllocationPaymentStatus::class,
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function overheadExpense(): BelongsTo
    {
        return $this->belongsTo(OverheadExpense::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
