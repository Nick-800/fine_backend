<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CashAccount extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'name',
        'currency',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
