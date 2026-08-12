<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InternalRestockRequestLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'internal_restock_request_id',
        'inventory_item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InternalRestockRequest::class, 'internal_restock_request_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
