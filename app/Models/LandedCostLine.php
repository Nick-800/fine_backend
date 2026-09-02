<?php

declare(strict_types=1);

namespace App\Models;

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
    ];

    protected $casts = [
        'type' => LandedCostType::class,
        'amount' => 'decimal:4',
        'is_confirmed' => 'boolean',
    ];

    public function importOrder(): BelongsTo
    {
        return $this->belongsTo(ImportOrder::class);
    }
}
