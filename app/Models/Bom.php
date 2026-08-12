<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Bom extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'product_id',
        'version',
        'is_active',
        'cloned_from_bom_id',
        'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function componentLines(): HasMany
    {
        return $this->hasMany(BomComponentLine::class);
    }

    public function laborRequirements(): HasMany
    {
        return $this->hasMany(LaborRequirement::class);
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_bom_id');
    }

    /**
     * Estimated material cost per unit — quote-side only. Actual costing reads
     * real lot costs at consumption.
     */
    public function estimatedMaterialCost(): float
    {
        return round((float) $this->componentLines->sum(
            fn (BomComponentLine $l) => (float) $l->quantity * (float) $l->estimated_unit_cost
        ), 4);
    }

    public function estimatedLaborCost(): float
    {
        return round((float) $this->laborRequirements->sum(
            fn (LaborRequirement $r) => (float) $r->estimated_hours * (float) $r->hourly_rate
        ), 4);
    }
}
