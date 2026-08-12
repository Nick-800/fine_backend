<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CutterWorkOrderLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'cutter_work_order_id',
        'requested_spec',
        'quantity',
        'template_length_m',
        'template_width_m',
        'template_height_m',
        'output_inventory_item_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'template_length_m' => 'decimal:4',
        'template_width_m' => 'decimal:4',
        'template_height_m' => 'decimal:4',
        'template_volume_m3' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        // Template volume is always derived, never entered — it is what the
        // client is billed for (CUT-06) and what block selection filters on, so
        // it must not be able to disagree with the dimensions.
        self::saving(function (CutterWorkOrderLine $line): void {
            if ($line->template_length_m !== null
                && $line->template_width_m !== null
                && $line->template_height_m !== null) {
                $line->template_volume_m3 = round(
                    (float) $line->template_length_m
                        * (float) $line->template_width_m
                        * (float) $line->template_height_m,
                    6
                );
            }
        });
    }

    public function cutterWorkOrder(): BelongsTo
    {
        return $this->belongsTo(CutterWorkOrder::class);
    }

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'output_inventory_item_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(FoamBlockConsumption::class);
    }

    public function hasTemplate(): bool
    {
        return $this->template_volume_m3 !== null && (float) $this->template_volume_m3 > 0;
    }

    /**
     * Total material needed: one template per unit ordered.
     */
    public function requiredVolumeM3(): float
    {
        return round((float) $this->template_volume_m3 * $this->quantity, 6);
    }
}
