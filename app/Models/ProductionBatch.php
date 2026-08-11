<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionBatchStatus;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionBatch extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * `next_sequence` and `scrap_volume_m3` are deliberately excluded: both are
     * derived from block registration and are only ever written by
     * ProductionBatchService, never by client input.
     */
    protected $fillable = [
        'operating_unit_id',
        'requested_by_client_id',
        'operation_number',
        'bun_width_m',
        'formula_params',
        'status',
        'material_cost',
        'record_version',
    ];

    protected $casts = [
        'operation_number' => 'integer',
        'bun_width_m' => 'decimal:3',
        'formula_params' => 'array',
        'status' => ProductionBatchStatus::class,
        'material_cost' => 'decimal:4',
        'scrap_volume_m3' => 'decimal:4',
        'next_sequence' => 'integer',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function requestedByClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'requested_by_client_id');
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }
}
