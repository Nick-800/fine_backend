<?php

declare(strict_types=1);

namespace App\Models;

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
        'unit_id',
        'account_code',
        'name',
        'type',
        'section',
        'currency',
        'parent_account_id',
    ];

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
}
