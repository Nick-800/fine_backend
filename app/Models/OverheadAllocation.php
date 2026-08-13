<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationMethod;
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
    ];

    protected $casts = [
        'method' => AllocationMethod::class,
        'amount' => 'decimal:4',
        'absorbed' => 'boolean',
    ];

    public function overheadExpense(): BelongsTo
    {
        return $this->belongsTo(OverheadExpense::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
