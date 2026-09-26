<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarehouseTransferStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseTransfer extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'transfer_number',
        'from_warehouse_id',
        'to_warehouse_id',
        'status',
        'reason',
        'completed_at',
        'record_version',
    ];

    protected $casts = [
        'status' => WarehouseTransferStatus::class,
        'completed_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseTransferLine::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
