<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConsumptionReport extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'production_batch_id',
        'reported_at',
        'record_version',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsumptionLine::class);
    }

    /**
     * Total material cost of the run — the numerator for per-block apportionment.
     */
    public function materialCost(): float
    {
        return round(
            (float) $this->lines->sum(
                fn (ConsumptionLine $line) => (float) $line->quantity_consumed * (float) $line->unit_cost_at_consumption
            ),
            4
        );
    }
}
