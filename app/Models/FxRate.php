<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FxRate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'captured_at',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'captured_at' => 'datetime',
    ];
}
