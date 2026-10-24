<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 08 Wave 2 / TRS-04: Cleared ledger line in a bank reconciliation.
 */
final class BankReconciliationLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'bank_reconciliation_id',
        'journal_line_id',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
