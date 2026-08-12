<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BomComponentLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'bom_id',
        'inventory_item_id',
        'quantity',
        'estimated_unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'estimated_unit_cost' => 'decimal:4',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
