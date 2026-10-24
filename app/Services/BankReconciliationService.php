<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankReconciliationStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\CompanyClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 08 Wave 2 / TRS-04: Bank reconciliation service (التسوية المصرفية).
 * A bank reconciliation verifies ledger postings against an external statement;
 * finishes strictly at zero difference and clears each ledger line at most once.
 */
final class BankReconciliationService
{
    /**
     * Start a draft reconciliation for a bank treasury.
     *
     * @throws LedgerRuleException
     */
    public function start(
        CashAccount $cashAccount,
        string $statementDate,
        float $statementBalance,
        User $user,
        ?string $notes = null,
    ): BankReconciliation {
        $cashAccount->loadMissing(['account', 'operatingUnit']);

        if ($cashAccount->kind !== CashAccount::KIND_BANK) {
            throw new LedgerRuleException('Only bank accounts can be reconciled.', 'RECONCILIATION_NOT_BANK');
        }

        if ($cashAccount->account_id === null || $cashAccount->account === null) {
            throw new LedgerRuleException("Treasury '{$cashAccount->name}' is not linked to a ledger account.", 'TREASURY_NOT_LINKED');
        }

        return DB::transaction(function () use ($cashAccount, $statementDate, $statementBalance, $user, $notes): BankReconciliation {
            // Lock the treasury so two people can't open two drafts for it at once.
            CashAccount::whereKey($cashAccount->id)->lockForUpdate()->first();

            $hasDraft = BankReconciliation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('status', BankReconciliationStatus::Draft)
                ->exists();

            if ($hasDraft) {
                throw new LedgerRuleException('A draft reconciliation already exists for this bank account.', 'RECONCILIATION_DRAFT_EXISTS');
            }

            $lastFinished = BankReconciliation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('status', BankReconciliationStatus::Finished)
                ->latest('statement_date')
                ->first();

            if ($lastFinished !== null && Carbon::parse($statementDate)->lte(Carbon::parse((string) $lastFinished->statement_date))) {
                throw new LedgerRuleException('The statement date must be after the last finished reconciliation date.', 'RECONCILIATION_DATE_BEFORE_LAST');
            }

            if (Carbon::parse($statementDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The statement date cannot be in the future.', 'RECONCILIATION_DATE_IN_FUTURE');
            }

            $companyId = $cashAccount->operatingUnit?->company_id ?? Company::query()->value('id');

            return BankReconciliation::create([
                'company_id' => $companyId,
                'cash_account_id' => $cashAccount->id,
                'statement_date' => $statementDate,
                'statement_balance' => round($statementBalance, 4),
                'status' => BankReconciliationStatus::Draft,
                'notes' => $notes,
                'created_by_user_id' => $user->id,
            ]);
        });
    }

    /**
     * Reconcilable candidate lines for this statement: posted lines on the treasury's
     * ledger account with entry_date <= statement_date that are not in any FINISHED
     * reconciliation (except this one if already finished). Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(BankReconciliation $rec): array
    {
        $rec->loadMissing('cashAccount.account');
        $cashAccount = $rec->cashAccount;
        $accountId = $cashAccount->account_id;

        $statementDate = $rec->statement_date instanceof Carbon
            ? $rec->statement_date->toDateString()
            : (string) $rec->statement_date;
        $toNext = Carbon::parse($statementDate)->addDay()->toDateString();

        $finishedLineIds = BankReconciliationLine::query()
            ->whereHas('reconciliation', function ($q) use ($rec): void {
                $q->where('status', BankReconciliationStatus::Finished)
                    ->where('id', '!=', $rec->id);
            })
            ->pluck('journal_line_id');

        $thisRecClearedLineIds = BankReconciliationLine::query()
            ->where('bank_reconciliation_id', $rec->id)
            ->pluck('journal_line_id')
            ->all();

        $clearedSet = array_flip($thisRecClearedLineIds);

        $lines = JournalLine::query()
            ->posted()
            ->with('journalEntry')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.entry_date', '<', $toNext)
            ->whereNotIn('journal_lines.id', $finishedLineIds)
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entries.created_at', 'asc')
            ->orderBy('journal_entries.reference', 'asc')
            ->orderBy('journal_lines.id', 'asc')
            ->select('journal_lines.*')
            ->get();

        $isForeign = strtoupper(trim((string) $cashAccount->currency)) !== 'LYD';

        $candidates = [];
        foreach ($lines as $line) {
            $entry = $line->journalEntry;
            $debit = $isForeign
                ? ((float) $line->debit > 0 ? (float) ($line->original_amount ?? $line->debit) : 0.0)
                : (float) $line->debit;
            $credit = $isForeign
                ? ((float) $line->credit > 0 ? (float) ($line->original_amount ?? $line->credit) : 0.0)
                : (float) $line->credit;

            $item = [
                'id' => (string) $line->id,
                'entry_date' => $entry->entry_date->toDateString(),
                'reference' => $entry->reference,
                'description' => $entry->description,
                'memo' => $line->memo,
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
                'cleared' => isset($clearedSet[$line->id]),
            ];

            if ($isForeign) {
                $item['currency'] = $line->currency ?? $cashAccount->currency;
            }

            $candidates[] = $item;
        }

        return $candidates;
    }

    /**
     * Tick or untick lines in a draft reconciliation.
     *
     * @param  list<string>  $lineIds
     *
     * @throws LedgerRuleException
     */
    public function toggle(BankReconciliation $rec, array $lineIds, bool $cleared): void
    {
        DB::transaction(function () use ($rec, $lineIds, $cleared): void {
            $this->toggleLocked(BankReconciliation::whereKey($rec->id)->lockForUpdate()->firstOrFail(), $lineIds, $cleared);
        });
    }

    /**
     * @param  list<string>  $lineIds
     */
    private function toggleLocked(BankReconciliation $rec, array $lineIds, bool $cleared): void
    {
        if ($rec->status !== BankReconciliationStatus::Draft) {
            throw new LedgerRuleException('This reconciliation is already finished and cannot be modified.', 'RECONCILIATION_FINISHED');
        }

        $uniqueLineIds = array_values(array_unique($lineIds));
        if (empty($uniqueLineIds)) {
            return;
        }

        $candidates = $this->candidates($rec);
        $candidateIds = array_column($candidates, 'id');
        $candidateSet = array_flip($candidateIds);

        foreach ($uniqueLineIds as $lineId) {
            if (! isset($candidateSet[$lineId])) {
                throw new LedgerRuleException("Line {$lineId} is not reconcilable for this statement.", 'LINE_NOT_RECONCILABLE');
            }
        }

        $alreadyReconciled = BankReconciliationLine::query()
            ->whereIn('journal_line_id', $uniqueLineIds)
            ->where('bank_reconciliation_id', '!=', $rec->id)
            ->exists();

        if ($alreadyReconciled) {
            throw new LedgerRuleException('One or more lines are already reconciled in another reconciliation.', 'LINE_ALREADY_RECONCILED');
        }

        if ($cleared) {
            foreach ($uniqueLineIds as $lineId) {
                BankReconciliationLine::firstOrCreate([
                    'bank_reconciliation_id' => $rec->id,
                    'journal_line_id' => $lineId,
                ]);
            }
        } else {
            BankReconciliationLine::query()
                ->where('bank_reconciliation_id', $rec->id)
                ->whereIn('journal_line_id', $uniqueLineIds)
                ->delete();
        }
    }

    /**
     * Compute reconciliation summary figures in the treasury's currency.
     *
     * @return array{book_balance: float, opening_cleared_balance: float, cleared_debit: float, cleared_credit: float, cleared_balance: float, statement_balance: float, difference: float, uncleared_deposits: float, uncleared_payments: float}
     */
    public function summary(BankReconciliation $rec): array
    {
        $rec->loadMissing('cashAccount');
        $cashAccount = $rec->cashAccount;
        $accountId = $cashAccount->account_id;
        $isForeign = strtoupper(trim((string) $cashAccount->currency)) !== 'LYD';

        $statementDate = $rec->statement_date instanceof Carbon
            ? $rec->statement_date->toDateString()
            : (string) $rec->statement_date;
        $toNext = Carbon::parse($statementDate)->addDay()->toDateString();

        $bookBalanceQuery = JournalLine::query()
            ->posted()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.entry_date', '<', $toNext);

        if ($isForeign) {
            $rawBook = $bookBalanceQuery->selectRaw('COALESCE(SUM(CASE WHEN journal_lines.debit > 0 THEN COALESCE(journal_lines.original_amount, journal_lines.debit) ELSE -COALESCE(journal_lines.original_amount, journal_lines.credit) END), 0) as bal')->value('bal');
        } else {
            $rawBook = $bookBalanceQuery->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as bal')->value('bal');
        }
        $bookBalance = round((float) $rawBook, 4);

        $lastFinishedQuery = BankReconciliation::query()
            ->where('cash_account_id', $rec->cash_account_id)
            ->where('status', BankReconciliationStatus::Finished)
            ->where('id', '!=', $rec->id);

        if ($rec->status === BankReconciliationStatus::Finished) {
            $lastFinishedQuery->where('statement_date', '<', $rec->statement_date);
        }

        $lastFinished = $lastFinishedQuery->latest('statement_date')->first();
        $openingClearedBalance = $lastFinished !== null ? round((float) $lastFinished->statement_balance, 4) : 0.0;

        $candidates = $this->candidates($rec);
        $clearedDebit = 0.0;
        $clearedCredit = 0.0;
        $unclearedDeposits = 0.0;
        $unclearedPayments = 0.0;

        foreach ($candidates as $cand) {
            if ($cand['cleared']) {
                $clearedDebit += $cand['debit'];
                $clearedCredit += $cand['credit'];
            } else {
                $unclearedDeposits += $cand['debit'];
                $unclearedPayments += $cand['credit'];
            }
        }

        $clearedDebit = round($clearedDebit, 4);
        $clearedCredit = round($clearedCredit, 4);
        $unclearedDeposits = round($unclearedDeposits, 4);
        $unclearedPayments = round($unclearedPayments, 4);

        $clearedBalance = round($openingClearedBalance + $clearedDebit - $clearedCredit, 4);
        $statementBalance = round((float) $rec->statement_balance, 4);
        $difference = round($statementBalance - $clearedBalance, 4);

        return [
            'book_balance' => $bookBalance,
            'opening_cleared_balance' => $openingClearedBalance,
            'cleared_debit' => $clearedDebit,
            'cleared_credit' => $clearedCredit,
            'cleared_balance' => $clearedBalance,
            'statement_balance' => $statementBalance,
            'difference' => $difference,
            'uncleared_deposits' => $unclearedDeposits,
            'uncleared_payments' => $unclearedPayments,
        ];
    }

    /**
     * Finish a draft reconciliation. Difference must be zero (|diff| < 0.005).
     *
     * @throws LedgerRuleException
     */
    public function finish(BankReconciliation $rec, User $user): BankReconciliation
    {
        return DB::transaction(function () use ($rec, $user): BankReconciliation {
            return $this->finishLocked(BankReconciliation::whereKey($rec->id)->lockForUpdate()->firstOrFail(), $user);
        });
    }

    private function finishLocked(BankReconciliation $rec, User $user): BankReconciliation
    {
        if ($rec->status !== BankReconciliationStatus::Draft) {
            throw new LedgerRuleException('This reconciliation is already finished.', 'RECONCILIATION_FINISHED');
        }

        $summary = $this->summary($rec);
        $difference = $summary['difference'];

        if (abs($difference) >= 0.005) {
            throw new LedgerRuleException(
                "Reconciliation difference must be zero to finish (current difference: {$difference}).",
                'RECONCILIATION_NOT_BALANCED',
                ['difference' => $difference],
            );
        }

        $rec->update([
            'status' => BankReconciliationStatus::Finished,
            'finished_by_user_id' => $user->id,
            'finished_at' => Carbon::now(),
        ]);

        return $rec;
    }

    /**
     * Discard a draft reconciliation and its cleared lines.
     *
     * @throws LedgerRuleException
     */
    public function discard(BankReconciliation $rec): void
    {
        if ($rec->status !== BankReconciliationStatus::Draft) {
            throw new LedgerRuleException('A finished reconciliation cannot be discarded.', 'RECONCILIATION_FINISHED');
        }

        DB::transaction(function () use ($rec): void {
            $rec->lines()->delete();
            $rec->delete();
        });
    }
}
