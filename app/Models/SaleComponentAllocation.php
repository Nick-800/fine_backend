<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reserved lot set aside for one bundle component, consumed at delivery.
 */
final class SaleComponentAllocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'sale_bundle_component_id',
        'stock_lot_id',
        'quantity',
        'delivered_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'delivered_at' => 'datetime',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(SaleBundleComponent::class, 'sale_bundle_component_id');
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }
}
