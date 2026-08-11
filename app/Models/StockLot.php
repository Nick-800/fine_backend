<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WarehouseOperatingUnitScope;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockLot extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'inventory_item_id',
        'warehouse_id',
        'lot_number',
        'sequence_in_batch',
        'pressure',
        'remnant_of_lot_id',
        'quantity',
        'container_quantity',
        'length_m',
        'width_m',
        'height_m',
        'volume_m3',
        'weight_kg',
        'unit_cost',
        'grade',
        'status',
        'attribute_values',
        'production_batch_id',
        'record_version',
    ];

    protected $casts = [
        'sequence_in_batch' => 'integer',
        'pressure' => 'integer',
        'quantity' => 'decimal:4',
        'container_quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'volume_m3' => 'decimal:4',
        'weight_kg' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'attribute_values' => 'array',
        'record_version' => 'integer',
    ];

    protected static function booted(): void
    {
        // Stock lots have no operating_unit_id of their own; they belong to a unit
        // only through their warehouse.
        static::addGlobalScope(new WarehouseOperatingUnitScope);

        static::saving(function (StockLot $stockLot): void {
            if ($stockLot->length_m !== null && $stockLot->width_m !== null && $stockLot->height_m !== null) {
                $stockLot->volume_m3 = round(
                    (float) $stockLot->length_m * (float) $stockLot->width_m * (float) $stockLot->height_m,
                    4
                );
            }
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function remnantOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remnant_of_lot_id');
    }
}
