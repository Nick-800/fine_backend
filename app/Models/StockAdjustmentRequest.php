<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentRequest extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'stock_lot_id',
        'reason_code',
        'quantity_delta',
        'notes',
        'status',
        'requested_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'record_version',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:4',
        'approved_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
