<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A foam block reserved for a cutter order, its cost and size locked in at
 * attachment. Written only by CutterWorkOrderService.
 */
final class CutterWorkOrderBlock extends Model
{
    use HasUuids;

    protected $fillable = [
        'cutter_work_order_id',
        'stock_lot_id',
        'unit_cost_snapshot',
        'length_m_snapshot',
        'width_m_snapshot',
        'height_m_snapshot',
        'volume_m3_snapshot',
    ];

    protected $casts = [
        'unit_cost_snapshot' => 'decimal:4',
        'length_m_snapshot' => 'decimal:4',
        'width_m_snapshot' => 'decimal:4',
        'height_m_snapshot' => 'decimal:4',
        'volume_m3_snapshot' => 'decimal:6',
    ];

    public function cutterWorkOrder(): BelongsTo
    {
        return $this->belongsTo(CutterWorkOrder::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }
}
