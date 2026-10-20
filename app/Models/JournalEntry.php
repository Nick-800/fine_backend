<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalEntryType;
use App\Exceptions\LedgerRuleException;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class JournalEntry extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'reference',
        'entry_date',
        'description',
        'source_document_type',
        'source_document_id',
        'is_manual',
        'entry_type',
        'reversal_of_id',
        'reversal_reason',
        'created_by_user_id',
        'record_version',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_manual' => 'boolean',
        'entry_type' => JournalEntryType::class,
        'record_version' => 'integer',
    ];

    /**
     * ACC-13: a posted entry never changes or disappears; a correction is a
     * reversal. Query-builder maintenance bypasses model events on purpose.
     */
    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LedgerRuleException('Posted journal entries cannot be changed; reverse them instead.', 'LEDGER_IMMUTABLE');
        });

        self::deleting(function (): void {
            throw new LedgerRuleException('Posted journal entries cannot be deleted; reverse them instead.', 'LEDGER_IMMUTABLE');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * The entry this one reverses (ACC-13).
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The entry that reversed this one, if any. Read from the reversal's side,
     * so the original row is never written to.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * Closing entries and the reversals that undid them (a reopened year).
     *
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopeClosingRelated(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('entry_type', JournalEntryType::Closing->value)
            ->orWhereIn('reversal_of_id', self::query()->where('entry_type', JournalEntryType::Closing->value)->select('id')));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 4);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 4);
    }

    /**
     * ACC-01. Read back from the stored lines rather than trusting the input
     * that created them, so this can also be used to audit existing entries.
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.0001;
    }
}
