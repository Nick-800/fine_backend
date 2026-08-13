<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OverheadAllocationRule extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'method',
        'percentages',
        'is_active',
    ];

    protected $casts = [
        'method' => AllocationMethod::class,
        'percentages' => 'array',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
