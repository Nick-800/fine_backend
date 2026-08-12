<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Financial statements derived from journal lines (ACC-05: read-only views,
 * never stored). Lines carry the operating unit, so every report can serve
 * both the company-wide and the per-unit question with the same query.
 *
 * No closing entries exist in this ledger — revenue and expense accounts are
 * never zeroed into equity — so the balance sheet folds the running net income
 * into the equity section itself. Without that the sheet could never balance.
 */
final class FinancialReportService
{
    private const BALANCE_TOLERANCE = 0.0001;

    /**
     * @return array<string, mixed>
     */
    public function incomeStatement(?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $rows = $this->accountActivity(['revenue', 'expense'], $from, $to, $operatingUnitId);

        $revenue = [];
        $expenses = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'revenue') {
                $revenue[] = $this->reportRow($row, (float) $row['credit'] - (float) $row['debit']);
            } else {
                $expenses[] = $this->reportRow($row, (float) $row['debit'] - (float) $row['credit']);
            }
        }

        $totalRevenue = round(array_sum(array_column($revenue, 'balance')), 4);
        $totalExpenses = round(array_sum(array_column($expenses, 'balance')), 4);

        return [
            'from' => $from,
            'to' => $to,
            'operating_unit_id' => $operatingUnitId,
            'revenue' => ['rows' => $revenue, 'total' => $totalRevenue],
            'expenses' => ['rows' => $expenses, 'total' => $totalExpenses],
            'net_income' => round($totalRevenue - $totalExpenses, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(?string $asOf = null, ?string $operatingUnitId = null): array
    {
        $asOf ??= now()->toDateString();

        $rows = $this->accountActivity(['asset', 'liability', 'equity'], null, $asOf, $operatingUnitId);

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'asset') {
                $assets[] = $this->reportRow($row, (float) $row['debit'] - (float) $row['credit']);
            } elseif ($row['type'] === 'liability') {
                $liabilities[] = $this->reportRow($row, (float) $row['credit'] - (float) $row['debit']);
            } else {
                $equity[] = $this->reportRow($row, (float) $row['credit'] - (float) $row['debit']);
            }
        }

        $income = $this->incomeStatement(null, $asOf, $operatingUnitId);

        $totalAssets = round(array_sum(array_column($assets, 'balance')), 4);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'balance')), 4);
        $totalEquity = round(array_sum(array_column($equity, 'balance')) + $income['net_income'], 4);

        return [
            'as_of' => $asOf,
            'operating_unit_id' => $operatingUnitId,
            'assets' => ['rows' => $assets, 'total' => $totalAssets],
            'liabilities' => ['rows' => $liabilities, 'total' => $totalLiabilities],
            'equity' => [
                'rows' => $equity,
                'retained_current_period' => $income['net_income'],
                'total' => $totalEquity,
            ],
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < self::BALANCE_TOLERANCE,
        ];
    }

    /**
     * Per-unit P&L rollup. Lines posted without a unit (company-level entries)
     * gather under a null unit id so nothing silently drops out of the total.
     *
     * @return array<string, mixed>
     */
    public function unitProfitability(?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('operating_units', 'operating_units.id', '=', 'journal_lines.operating_unit_id')
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->selectRaw('journal_lines.operating_unit_id, operating_units.name as unit_name, accounts.type')
            ->selectRaw('SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.operating_unit_id', 'operating_units.name', 'accounts.type');

        $this->applyDateRange($query, $from, $to);

        if ($operatingUnitId !== null) {
            $query->where('journal_lines.operating_unit_id', $operatingUnitId);
        }

        $units = [];

        foreach ($query->get() as $row) {
            $key = $row->operating_unit_id ?? 'unallocated';

            $units[$key] ??= [
                'operating_unit_id' => $row->operating_unit_id,
                'unit_name' => $row->unit_name ?? 'unallocated',
                'revenue' => 0.0,
                'expenses' => 0.0,
            ];

            if ($row->type === 'revenue') {
                $units[$key]['revenue'] = round((float) $row->credit - (float) $row->debit, 4);
            } else {
                $units[$key]['expenses'] = round((float) $row->debit - (float) $row->credit, 4);
            }
        }

        $rows = array_values(array_map(function (array $unit): array {
            $unit['net'] = round($unit['revenue'] - $unit['expenses'], 4);

            return $unit;
        }, $units));

        usort($rows, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_net' => round(array_sum(array_column($rows, 'net')), 4),
        ];
    }

    /**
     * Per-account debit/credit sums for the given account types.
     *
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function accountActivity(array $types, ?string $from, ?string $to, ?string $operatingUnitId): array
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('accounts.type', $types)
            ->selectRaw('accounts.account_code, accounts.name, accounts.type')
            ->selectRaw('SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('accounts.account_code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.account_code');

        $this->applyDateRange($query, $from, $to);

        if ($operatingUnitId !== null) {
            $query->where('journal_lines.operating_unit_id', $operatingUnitId);
        }

        return $query->get()->map(fn ($row) => [
            'account_code' => $row->account_code,
            'name' => $row->name,
            'type' => $row->type,
            'debit' => round((float) $row->debit, 4),
            'credit' => round((float) $row->credit, 4),
        ])->all();
    }

    private function applyDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $to);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function reportRow(array $row, float $balance): array
    {
        return [
            'account_code' => $row['account_code'],
            'name' => $row['name'],
            'balance' => round($balance, 4),
        ];
    }
}
