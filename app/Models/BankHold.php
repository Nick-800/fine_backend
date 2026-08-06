<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BankHold extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'payment_request_id',
        'held_amount_lyd',
        'exact_amount_used',
        'released_amount',
        'bank_reference',
    ];

    protected $casts = [
        'held_amount_lyd' => 'decimal:4',
        'exact_amount_used' => 'decimal:4',
        'released_amount' => 'decimal:4',
    ];

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
