<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayableSettlement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'operating_unit_id',
        'account_code',
        'method',
        'amount',
        'reference',
        'cheque_id',
        'settled_by_user_id',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'settled_at' => 'date',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }
}
