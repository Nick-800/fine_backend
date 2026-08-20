<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PosDailyClose extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'close_date',
        'expected_cash',
        'counted_cash',
        'difference',
        'sales_count',
        'total_sales',
        'notes',
        'closed_by_user_id',
    ];

    protected $casts = [
        'close_date' => 'date',
        'expected_cash' => 'decimal:4',
        'counted_cash' => 'decimal:4',
        'difference' => 'decimal:4',
        'total_sales' => 'decimal:4',
        'sales_count' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
