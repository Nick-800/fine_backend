<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FoamBlockConsumption extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'cutter_work_order_line_id',
        'stock_lot_id',
        'block_volume_m3',
        'volume_consumed_m3',
        'consumption_type',
        'consumed_cost',
        'remainder_cost',
    ];

    protected $casts = [
        'block_volume_m3' => 'decimal:6',
        'volume_consumed_m3' => 'decimal:6',
        'consumed_cost' => 'decimal:4',
        'remainder_cost' => 'decimal:4',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(CutterWorkOrderLine::class, 'cutter_work_order_line_id');
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    /**
     * The block's whole cost. The two halves always sum back to it, so no
     * material value goes missing between the block and its outputs.
     */
    public function totalCost(): float
    {
        return round((float) $this->consumed_cost + (float) $this->remainder_cost, 4);
    }
}
