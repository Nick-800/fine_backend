<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TreasuryTransferStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 08 Wave 2 / TRS-03: Transfers between treasuries (التحويلات بين الخزائن).
 */
final class TreasuryTransfer extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'operating_unit_id',
        'transfer_number',
        'from_cash_account_id',
        'to_cash_account_id',
        'amount',
        'currency',
        'fx_rate',
        'amount_lyd',
        'transfer_date',
        'notes',
        'status',
        'created_by_user_id',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'journal_entry_id',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => TreasuryTransferStatus::class,
            'amount' => 'decimal:4',
            'fx_rate' => 'decimal:8',
            'amount_lyd' => 'decimal:4',
            'transfer_date' => 'date',
            'decided_at' => 'datetime',
            'record_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function fromCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_cash_account_id');
    }

    public function toCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_cash_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
