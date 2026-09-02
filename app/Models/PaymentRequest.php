<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class PaymentRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'import_order_id',
        'route',
        'invoice_ref',
        'amount_requested',
        'status',
        'fx_rate_used',
        'extra_allocation_note',
    ];

    protected $casts = [
        'route' => PaymentRoute::class,
        'status' => PaymentRequestStatus::class,
        'amount_requested' => 'decimal:4',
        'fx_rate_used' => 'decimal:6',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function importOrder(): BelongsTo
    {
        return $this->belongsTo(ImportOrder::class);
    }

    public function bankHold(): HasOne
    {
        return $this->hasOne(BankHold::class);
    }
}
