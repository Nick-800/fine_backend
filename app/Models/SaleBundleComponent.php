<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleComponentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of what a sold bundle contains — an item, how many and its size.
 * Written only by BundleFulfillmentService.
 */
final class SaleBundleComponent extends Model
{
    use HasUuids;

    protected $fillable = [
        'sales_order_line_id',
        'position',
        'inventory_item_id',
        'quantity',
        'length_m',
        'width_m',
        'height_m',
        'reference_price',
        'status',
        'cutter_work_order_line_id',
        'unit_cost_actual',
        'notes',
    ];

    protected $casts = [
        'status' => SaleComponentStatus::class,
        'quantity' => 'decimal:4',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'volume_m3' => 'decimal:6',
        'reference_price' => 'decimal:4',
        'unit_cost_actual' => 'decimal:4',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        self::saving(function (SaleBundleComponent $component): void {
            $component->volume_m3 = ($component->length_m !== null && $component->width_m !== null && $component->height_m !== null)
                ? round((float) $component->length_m * (float) $component->width_m * (float) $component->height_m, 6)
                : null;
        });
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SaleComponentAllocation::class);
    }

    public function cutterWorkOrderLine(): BelongsTo
    {
        return $this->belongsTo(CutterWorkOrderLine::class);
    }

    public function allocatedQuantity(): float
    {
        return round((float) $this->allocations()->sum('quantity'), 4);
    }

    public function hasSize(): bool
    {
        return $this->length_m !== null && $this->width_m !== null && $this->height_m !== null;
    }

    /**
     * "Item 200×70×10 سم" — the wording on the delivery note and cutter sheet.
     */
    public function label(): string
    {
        $name = $this->inventoryItem?->name ?? 'Item';

        if (! $this->hasSize()) {
            return $name;
        }

        $cm = fn ($metres): string => rtrim(rtrim(number_format((float) $metres * 100, 1, '.', ''), '0'), '.');

        return "{$name} {$cm($this->length_m)}×{$cm($this->width_m)}×{$cm($this->height_m)} سم";
    }
}
