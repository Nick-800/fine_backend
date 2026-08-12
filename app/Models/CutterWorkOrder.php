<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CutterWorkOrderStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CutterWorkOrder extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * `wip_cost` is not fillable: it is the running total of material drawn out
     * of consumed blocks and is only ever moved by CutterWorkOrderService.
     */
    protected $fillable = [
        'operating_unit_id',
        'client_id',
        'order_number',
        'status',
        'notes',
        'record_version',
    ];

    protected $casts = [
        'status' => CutterWorkOrderStatus::class,
        'wip_cost' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CutterWorkOrderLine::class);
    }

    public function byproductYields(): HasMany
    {
        return $this->hasMany(ByproductYield::class);
    }

    public function consumptions(): HasManyThrough
    {
        return $this->hasManyThrough(
            FoamBlockConsumption::class,
            CutterWorkOrderLine::class,
            'cutter_work_order_id',
            'cutter_work_order_line_id',
        );
    }

    /**
     * An internal order comes from another unit rather than a paying client, so
     * it skips the credit check the Sales module will apply to external ones.
     */
    public function isInternal(): bool
    {
        return $this->client_id === null;
    }
}
