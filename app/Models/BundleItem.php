<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BundleItem extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'bundle_id',
        'inventory_item_id',
        'suggested_quantity',
        'length_m',
        'width_m',
        'height_m',
        'position',
    ];

    protected $casts = [
        'suggested_quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
