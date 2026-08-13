<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class LaborRoleRate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'role',
        'hourly_rate',
        'effective_from',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:4',
        'effective_from' => 'date',
    ];

    /**
     * HR-04: the rate in force for a role on a given date — the newest
     * version whose effective date is not in the future of that date.
     * Null when the role has no rate history yet.
     */
    public static function rateFor(string $role, Carbon|string|null $date = null): ?float
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());

        $rate = self::where('role', $role)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->value('hourly_rate');

        return $rate !== null ? (float) $rate : null;
    }
}
