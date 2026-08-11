<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConsumptionLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'consumption_report_id',
        'tank_stock_id',
        'chemical_inventory_item_id',
        'quantity_consumed',
        'unit_cost_at_consumption',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:4',
        'unit_cost_at_consumption' => 'decimal:4',
    ];

    public function consumptionReport(): BelongsTo
    {
        return $this->belongsTo(ConsumptionReport::class);
    }

    public function chemicalItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'chemical_inventory_item_id');
    }

    public function tankStock(): BelongsTo
    {
        return $this->belongsTo(TankStock::class);
    }

    public function lineCost(): float
    {
        return round((float) $this->quantity_consumed * (float) $this->unit_cost_at_consumption, 4);
    }
}
