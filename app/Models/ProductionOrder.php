<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionOrderStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionOrder extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * material_cost, labor_cost and finished_stock_lot_id are excluded: they are
     * actuals written only by ProductionOrderService as the order moves.
     */
    protected $fillable = [
        'operating_unit_id',
        'product_id',
        'bom_id',
        'client_id',
        'order_number',
        'quantity',
        'status',
        'notes',
        'record_version',
    ];

    protected $casts = [
        'status' => ProductionOrderStatus::class,
        'quantity' => 'integer',
        'material_cost' => 'decimal:4',
        'labor_cost' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(LaborLog::class);
    }

    public function finishedStockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'finished_stock_lot_id');
    }

    public function totalCost(): float
    {
        return round((float) $this->material_cost + (float) $this->labor_cost, 4);
    }
}
