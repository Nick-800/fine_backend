<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LaborRequirement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'bom_id',
        'role',
        'estimated_hours',
        'hourly_rate',
    ];

    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:4',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }
}
