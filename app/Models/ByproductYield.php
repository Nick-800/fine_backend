<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ByproductYield extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'cutter_work_order_id',
        'stock_lot_id',
        'weight_kg',
        'yield_cost',
        'weighed_by_user_id',
        'weighed_at',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:4',
        'yield_cost' => 'decimal:4',
        'weighed_at' => 'datetime',
    ];

    public function cutterWorkOrder(): BelongsTo
    {
        return $this->belongsTo(CutterWorkOrder::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function weighedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'weighed_by_user_id');
    }
}
