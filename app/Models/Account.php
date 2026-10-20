<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Account extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'chart_of_accounts_id',
        'account_code',
        'name',
        'type',
        'section',
        'currency',
        'status',
        'parent_account_id',
    ];

    // No $attributes default for status: the column's database default covers
    // it, and older data migrations that re-run the chart seeder insert
    // accounts before the status column exists.

    protected $casts = [
        'status' => AccountStatus::class,
    ];

    /**
     * ACC-14: frozen itself or under a frozen ancestor. Walks up the chart
     * (a handful of levels) with a guard against a corrupt cycle.
     */
    public function isFrozen(): bool
    {
        $seen = [];
        $current = $this;

        while ($current !== null && ! isset($seen[$current->id])) {
            if ($current->status === AccountStatus::Frozen) {
                return true;
            }

            $seen[$current->id] = true;
            $current = $current->parent_account_id !== null ? self::query()->find($current->parent_account_id) : null;
        }

        return false;
    }

    public function chartOfAccounts(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccounts::class, 'chart_of_accounts_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
