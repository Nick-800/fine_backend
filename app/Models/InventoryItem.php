<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'nominal_length_m',
        'nominal_width_m',
        'nominal_height_m',
        'nominal_volume_m3',
    ];

    protected $casts = [
        'default_attributes' => 'array',
        'container_capacity' => 'decimal:4',
        'nominal_length_m' => 'decimal:3',
        'nominal_width_m' => 'decimal:3',
        'nominal_height_m' => 'decimal:3',
        'nominal_volume_m3' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        self::saving(function (InventoryItem $item): void {
            if ($item->nominal_length_m !== null && $item->nominal_width_m !== null && $item->nominal_height_m !== null) {
                $item->nominal_volume_m3 = round(
                    (float) $item->nominal_length_m * (float) $item->nominal_width_m * (float) $item->nominal_height_m,
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }
}
