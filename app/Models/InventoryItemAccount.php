<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (inventory_item, event_type) → links an item to a specific
 * chart-of-accounts sub-account. Resolved by `InventoryItem::accountFor()`
 * during posting — absence triggers `INVENTORY_ACCOUNT_NOT_LINKED` 422.
 */
final class InventoryItemAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'inventory_item_id',
        'event_type',
        'account_id',
    ];

    protected $casts = [
        'event_type' => InventoryEventType::class,
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
