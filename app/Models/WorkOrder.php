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

    protected $appends = [
        'productSku',
        'createdAt',
    ];

    public function getProductSkuAttribute(): ?string
    {
        return $this->attributes['product_sku'] ?? null;
    }

    public function getCreatedAtAttribute(): ?string
    {
        return isset($this->attributes['created_at'])
            ? (string) $this->attributes['created_at']
            : null;
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
