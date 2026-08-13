<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepreciationEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'fixed_asset_id',
        'period',
        'amount',
        'book_value_after',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'book_value_after' => 'decimal:4',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
