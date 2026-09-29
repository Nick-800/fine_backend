<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Treasuries and bank accounts are company-wide: any unit's POS can receive
 * money into them, so they are deliberately not operating-unit scoped.
 * `operating_unit_id` only records the unit that owns the account.
 */
final class CashAccount extends Model
{
    use HasFactory, HasUuids;

    public const KIND_CASH = 'cash';

    public const KIND_BANK = 'bank';

    protected $fillable = [
        'operating_unit_id',
        'name',
        'kind',
        'account_id',
        'currency',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
    ];

    protected $attributes = [
        'kind' => self::KIND_CASH,
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    /**
     * The ledger account money received into this treasury is debited to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
