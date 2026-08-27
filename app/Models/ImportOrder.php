<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportOrderStatus;
use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ImportOrder extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'operating_unit_id',
        'supplier_id',
        'currency',
        'negotiated_price',
        'quantity',
        'booked_fx_rate',
        'status',
        'record_version',
    ];

    protected $casts = [
        'status' => ImportOrderStatus::class,
        'negotiated_price' => 'decimal:4',
        'quantity' => 'decimal:4',
        'booked_fx_rate' => 'decimal:6',
        'record_version' => 'integer',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function landedCostLines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class);
    }

    public function goodsReceipt(): HasOne
    {
        return $this->hasOne(GoodsReceipt::class);
    }
}
