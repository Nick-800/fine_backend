<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FiscalPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CompanyClock;
use App\Support\LedgerAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal year-end close, reopen, and preview operations (ACC-11, ACC-12).
 *
 * A closing entry moves revenue and expense balances of an ended fiscal year into
 * account 33 (أرباح وخسائر مرحلة), with one 33 line per operating unit.
 * Reopening reverses the closing entry while keeping months locked.
 */
final class FiscalYearService
{
    public function __construct(private readonly AccountingService $accountingService) {}

    /**
     * Preview closing lines and blockers before closing a fiscal year.
     *
     * @return array{
     *     year: FiscalYear,
     *     lines: list<array{account_code: string, account_name: string, operating_unit_id: string|null, unit_name: string|null, debit: float, credit: float}>,
     *     units: list<array{operating_unit_id: string|null, unit_name: string|null, net_income: float}>,
     *     net_income: float,
     *     total_debit: float,
     *     total_credit: float,
     *     blockers: list<array<string, mixed>>
     * }
     */
    public function preview(FiscalYear $year): array
    {
        $startDate = $year->start_date->toDateString();
        $endDatePlusOne = $year->end_date->copy()->addDay()->toDateString();

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->leftJoin('operating_units', 'operating_units.id', '=', 'journal_lines.operating_unit_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $year->company_id)
            ->where('journal_entries.entry_date', '>=', $startDate)
            ->where('journal_entries.entry_date', '<', $endDatePlusOne)
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->whereNotIn('journal_entries.id', JournalEntry::query()->closingRelated()->select('id'))
            ->selectRaw('
                journal_lines.account_id,
                accounts.account_code,
                accounts.name as account_name,
                journal_lines.operating_unit_id,
                operating_units.name as unit_name,
                SUM(journal_lines.debit) as total_debit,
                SUM(journal_lines.credit) as total_credit
            ')
            ->groupBy(
                'journal_lines.account_id',
                'accounts.account_code',
                'accounts.name',
                'journal_lines.operating_unit_id',
                'operating_units.name'
            )
            ->orderBy('accounts.account_code')
            ->get();

        $accountLines = [];
        $unitTotals = [];

        foreach ($rows as $row) {
            $totalDebit = (float) $row->total_debit;
            $totalCredit = (float) $row->total_credit;
            $net = round($totalDebit - $totalCredit, 4);

            $unitKey = $row->operating_unit_id ?? '__null__';
            $unitTotals[$unitKey] ??= [
                'operating_unit_id' => $row->operating_unit_id,
                'unit_name' => $row->unit_name,
                'net_income' => 0.0,
            ];
            $unitTotals[$unitKey]['net_income'] = round($unitTotals[$unitKey]['net_income'] + ($totalCredit - $totalDebit), 4);

            if (abs($net) < 0.0001) {
                continue;
            }

            if ($net > 0) {
                $accountLines[] = [
                    'account_code' => (string) $row->account_code,
                    'account_name' => (string) $row->account_name,
                    'operating_unit_id' => $row->operating_unit_id,
                    'unit_name' => $row->unit_name,
                    'debit' => 0.0,
                    'credit' => $net,
                ];
            } else {
                $accountLines[] = [
                    'account_code' => (string) $row->account_code,
                    'account_name' => (string) $row->account_name,
                    'operating_unit_id' => $row->operating_unit_id,
                    'unit_name' => $row->unit_name,
                    'debit' => abs($net),
                    'credit' => 0.0,
                ];
            }
        }

        $retainedAccount = Account::where('account_code', LedgerAccounts::RETAINED_EARNINGS)->first();
        $retainedName = $retainedAccount?->name ?? 'أرباح وخسائر مرحلة';

        $retainedLines = [];
        $unitsSummary = [];

        foreach ($unitTotals as $unitData) {
            $unitsSummary[] = [
                'operating_unit_id' => $unitData['operating_unit_id'],
                'unit_name' => $unitData['unit_name'],
                'net_income' => $unitData['net_income'],
            ];

            if (abs($unitData['net_income']) < 0.0001) {
                continue;
            }

            if ($unitData['net_income'] > 0) {
                $retainedLines[] = [
                    'account_code' => LedgerAccounts::RETAINED_EARNINGS,
                    'account_name' => $retainedName,
                    'operating_unit_id' => $unitData['operating_unit_id'],
                    'unit_name' => $unitData['unit_name'],
                    'debit' => 0.0,
                    'credit' => $unitData['net_income'],
                ];
            } else {
                $retainedLines[] = [
                    'account_code' => LedgerAccounts::RETAINED_EARNINGS,
                    'account_name' => $retainedName,
                    'operating_unit_id' => $unitData['operating_unit_id'],
                    'unit_name' => $unitData['unit_name'],
                    'debit' => abs($unitData['net_income']),
                    'credit' => 0.0,
                ];
            }
        }

        $lines = [...$accountLines, ...$retainedLines];
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 4);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 4);
        $netIncome = round(array_sum(array_column($unitsSummary, 'net_income')), 4);

        return [
            'year' => $year,
            'lines' => $lines,
            'units' => $unitsSummary,
            'net_income' => $netIncome,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'blockers' => $this->blockers($year),
        ];
    }

    /**
     * List all blockers preventing closing the fiscal year.
     *
     * @return list<array<string, mixed>>
     */
    public function blockers(FiscalYear $year): array
    {
        $blockers = [];

        if ($year->status === FiscalYearStatus::Closed) {
            $blockers[] = [
                'code' => 'YEAR_ALREADY_CLOSED',
                'message' => "Fiscal year {$year->name} is already closed.",
            ];
        }

        if (CompanyClock::today() <= $year->end_date->toDateString()) {
            $blockers[] = [
                'code' => 'YEAR_NOT_ENDED',
                'message' => "Fiscal year {$year->name} has not ended yet; a year can be closed from the day after it ends.",
            ];
        }

        $earlierOpen = FiscalYear::query()
            ->where('company_id', $year->company_id)
            ->where('start_date', '<', $year->start_date->toDateString())
            ->where('status', FiscalYearStatus::Open->value)
            ->exists();

        if ($earlierOpen) {
            $blockers[] = [
                'code' => 'PREVIOUS_YEAR_OPEN',
                'message' => 'An earlier fiscal year is still open; close it first.',
            ];
        }

        $openPeriods = $year->periods()
            ->where('status', FiscalPeriodStatus::Open->value)
            ->orderBy('period_number')
            ->get();

        if ($openPeriods->isNotEmpty()) {
            $blockers[] = [
                'code' => 'YEAR_PERIODS_NOT_LOCKED',
                'message' => 'All months of the fiscal year must be locked before closing.',
                'open_periods' => $openPeriods->map->label()->all(),
            ];
        }

        $retainedAccount = Account::where('account_code', LedgerAccounts::RETAINED_EARNINGS)->first();
        if ($retainedAccount === null || $retainedAccount->isFrozen() || Account::query()->where('parent_account_id', $retainedAccount->id)->exists()) {
            $blockers[] = [
                'code' => 'RETAINED_EARNINGS_NOT_POSTABLE',
                'message' => 'Retained earnings account 33 is missing, frozen, or has sub-accounts.',
                'account_code' => LedgerAccounts::RETAINED_EARNINGS,
            ];
        }

        return $blockers;
    }

    /**
     * Close the fiscal year and post the closing entry.
     *
     * @throws LedgerRuleException YEAR_ALREADY_CLOSED, YEAR_NOT_ENDED, PREVIOUS_YEAR_OPEN, YEAR_PERIODS_NOT_LOCKED, RETAINED_EARNINGS_NOT_POSTABLE
     */
    public function close(FiscalYear $year, User $user): FiscalYear
    {
        return DB::transaction(function () use ($year, $user): FiscalYear {
            $year = FiscalYear::lockForUpdate()->findOrFail($year->id);

            // Same checks, same order, as the preview shows them; the first one refuses.
            foreach ($this->blockers($year) as $blocker) {
                ['code' => $code, 'message' => $message] = $blocker;
                unset($blocker['code'], $blocker['message']);

                throw new LedgerRuleException($message, $code, $blocker);
            }

            $preview = $this->preview($year);
            $closingEntryId = null;

            if (! empty($preview['lines'])) {
                $closing = $this->accountingService->postClosingEntry($year, $preview['lines'], $user->id);
                $closingEntryId = $closing->id;
            }

            $year->update([
                'status' => FiscalYearStatus::Closed,
                'closed_at' => now(),
                'closed_by_user_id' => $user->id,
                'closing_entry_id' => $closingEntryId,
            ]);

            return $year->fresh(['periods', 'closedBy', 'reopenedBy', 'closingEntry']);
        });
    }

    /**
     * Reopen a closed fiscal year and reverse its closing entry.
     *
     * @throws LedgerRuleException YEAR_NOT_CLOSED, LATER_YEAR_CLOSED
     */
    public function reopen(FiscalYear $year, User $user, string $reason): FiscalYear
    {
        return DB::transaction(function () use ($year, $user, $reason): FiscalYear {
            $year = FiscalYear::lockForUpdate()->findOrFail($year->id);

            if ($year->status !== FiscalYearStatus::Closed) {
                throw new LedgerRuleException("Fiscal year {$year->name} is not closed.", 'YEAR_NOT_CLOSED');
            }

            $laterClosed = FiscalYear::query()
                ->where('company_id', $year->company_id)
                ->where('start_date', '>', $year->start_date->toDateString())
                ->where('status', FiscalYearStatus::Closed->value)
                ->exists();

            if ($laterClosed) {
                throw new LedgerRuleException('A later fiscal year is closed; reopen it first.', 'LATER_YEAR_CLOSED');
            }

            if ($year->closing_entry_id !== null) {
                $closing = JournalEntry::findOrFail($year->closing_entry_id);
                $this->accountingService->reverseClosingEntry($closing, $year, $reason, $user->id);
            }

            $year->update([
                'status' => FiscalYearStatus::Open,
                'reopened_at' => now(),
                'reopened_by_user_id' => $user->id,
                'reopen_reason' => $reason,
                'closing_entry_id' => null,
            ]);

            return $year->fresh(['periods', 'closedBy', 'reopenedBy', 'closingEntry']);
        });
    }
}
