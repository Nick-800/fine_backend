<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationPaymentStatus;
use App\Enums\LandedCostType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LandedCostLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'import_order_id',
        'type',
        'amount',
        'currency',
        'is_confirmed',
        'note',
        'status',
        'approved_by_user_id',
        'approved_at',
        'paid_by_user_id',
        'paid_at',
        'confirmation_note',
    ];

    protected $casts = [
        'type' => LandedCostType::class,
        'amount' => 'decimal:4',
        'is_confirmed' => 'boolean',
        'status' => AllocationPaymentStatus::class,
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function importOrder(): BelongsTo
    {
        return $this->belongsTo(ImportOrder::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * `is_confirmed` is now a derived view of payment status so the existing
     * ImportOrderStateService completion guard keeps working without a schema
     * change. Once status reaches Paid, the line is considered confirmed.
     */
    protected static function booted(): void
    {
        self::saving(function (LandedCostLine $line): void {
            if ($line->status === AllocationPaymentStatus::Paid) {
                $line->is_confirmed = true;
            }
        });
    }
}
