<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkOrder extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'operating_unit_id',
        'product_sku',
        'quantity',
        'status',
        'record_version',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
