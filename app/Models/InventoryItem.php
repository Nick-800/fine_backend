<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryEventType;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class InventoryItem extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'code',
        'item_type',
        'unit_of_measure',
        'primary_uom',
        'secondary_uom',
        'container_capacity',
        'empty_container_item_id',
        'default_attributes',
        'length_m',
        'width_m',
        'height_m',
        'volume_m3',
        'selling_price',
        'price_basis',
    ];

    protected $casts = [
        'default_attributes' => 'array',
        'container_capacity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'volume_m3' => 'decimal:4',
        'selling_price' => 'decimal:4',
    ];

    protected $attributes = [
        'price_basis' => 'unit',
    ];

    protected static function booted(): void
    {
        self::saving(function (InventoryItem $item): void {
            if ($item->length_m !== null && $item->width_m !== null && $item->height_m !== null) {
                $item->volume_m3 = round(
                    (float) $item->length_m * (float) $item->width_m * (float) $item->height_m,
                    4
                );
            }
        });
    }

    /**
     * The item representing this product's empty container, credited back to
     * stock when one drains.
     */
    public function emptyContainerItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'empty_container_item_id');
    }

    public function tracksContainers(): bool
    {
        return $this->container_capacity !== null && (float) $this->container_capacity > 0;
    }

    /**
     * The suggested selling price for a quantity of this item — the POS
     * starting price, always editable by the cashier.
     *
     * A 'unit' item prices per piece. An 'm3' item prices per cubic metre of
     * the given size, falling back to the catalog item's own dimensions.
     * Returns null when the item has no price, or an m3 item has no size.
     */
    public function priceFor(float $quantity, ?float $lengthM = null, ?float $widthM = null, ?float $heightM = null): ?float
    {
        if ($this->selling_price === null) {
            return null;
        }

        $rate = (float) $this->selling_price;

        if ($this->price_basis !== 'm3') {
            return round($rate * $quantity, 4);
        }

        $volume = ($lengthM !== null && $widthM !== null && $heightM !== null)
            ? $lengthM * $widthM * $heightM
            : ($this->volume_m3 !== null ? (float) $this->volume_m3 : null);

        if ($volume === null) {
            return null;
        }

        return round($rate * $volume * $quantity, 4);
    }

    /**
     * Physical lots of this item. StockLot's warehouse scope keeps it to the
     * caller's unit.
     */
    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * Per-event account overrides. The COA chart is mapped onto each item
     * by event (purchases, sales, returns, COGS, waste, discounts, transport,
     * sales commission, opening/ending).
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(InventoryItemAccount::class);
    }

    /**
     * Resolve the chart-of-accounts account to use when posting the given
     * event for this item. Returns the linked `Account` or `null` if no
     * override is set — the caller is responsible for turning `null` into a
     * hard `INVENTORY_ACCOUNT_NOT_LINKED` 422 (no canonical fallback).
     */
    public function accountFor(InventoryEventType $event): ?Account
    {
        $row = $this->accounts()->where('event_type', $event->value)->first();

        return $row?->account;
    }
}
