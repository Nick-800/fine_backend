<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChequeStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 08 Wave 2 / TRS-05: Customer and supplier cheques (الشيكات الواردة والصادرة).
 *
 * For outgoing cheques:
 * - deposit_cash_account_id holds the bank treasury the cheque is drawn on.
 * - supplier_id holds the payee supplier when issued to a vendor.
 * - payee_name holds the payee name when issued to a non-supplier counter account.
 * - counter_account_code holds the offset account code.
 * - issue_journal_entry_id links the issuance journal entry (reversed upon cancellation).
 */
final class Cheque extends Model
{
    use Auditable, BelongsToOperatingUnit, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'operating_unit_id',
        'cheque_number_internal',
        'direction',
        'cheque_no',
        'bank_name',
        'drawer_name',
        'client_id',
        'supplier_id',
        'payee_name',
        'counter_account_code',
        'amount',
        'currency',
        'issue_date',
        'due_date',
        'received_date',
        'is_certified',
        'status',
        'deposit_cash_account_id',
        'status_changed_at',
        'notes',
        'bounce_reason',
        'issue_journal_entry_id',
        'created_by_user_id',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChequeStatus::class,
            'amount' => 'decimal:4',
            'is_certified' => 'boolean',
            'issue_date' => 'date',
            'due_date' => 'date',
            'received_date' => 'date',
            'status_changed_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_code', 'account_code');
    }

    public function issueJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'issue_journal_entry_id');
    }

    public function depositCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'deposit_cash_account_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChequeEvent::class, 'cheque_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
