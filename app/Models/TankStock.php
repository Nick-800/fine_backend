<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TankStock extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'chemical_inventory_item_id',
        'operating_unit_id',
        'quantity_on_hand',
        'weighted_avg_unit_cost',
        'record_version',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'weighted_avg_unit_cost' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function chemicalItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'chemical_inventory_item_id');
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
