<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GoodsReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purchase_order_id',
        'warehouse_id',
        'received_qty',
        'condition_notes',
    ];

    protected $casts = [
        'received_qty' => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
