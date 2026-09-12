<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImportOrderItem extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'import_order_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'currency',
        'record_version',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function importOrder(): BelongsTo
    {
        return $this->belongsTo(ImportOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
