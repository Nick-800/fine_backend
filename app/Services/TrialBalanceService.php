<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance with opening, movement and closing columns (FR-FREP-011,
 * FR-FREP-008, BR-FREP-005).
 *
 * One grouped query sums each account's posted lines (ACC-16) before `from`
 * (opening) and from `from` through `to` (movement). Roll-ups up the chart
 * are done in PHP so the tree and level views reuse the same sums. Totals
 * always come from the accounts that carry lines, never from roll-ups, so a
 * header and its children are not counted twice.
 *
 * Dates are compared half-open (entry_date < to + 1 day) with plain Y-m-d
 * strings, which is correct whether a date is stored as 'Y-m-d' or as a
 * midnight timestamp.
 */
final class TrialBalanceService
{
    private const TOLERANCE = 0.0001;

    /**
     * @param  'leaf'|'level'|'tree'  $view
     * @return array{from: string|null, to: string|null, view: string, level: int|null, rows: list<array<string, mixed>>, totals: array<string, float>, total_debit: float, total_credit: float, balanced: bool}
     */
    public function build(
        ?string $from = null,
        ?string $to = null,
        ?string $operatingUnitId = null,
        bool $includeZero = false,
        string $view = 'leaf',
        ?int $level = null,
        bool $excludeClosing = false,
    ): array {
        $sums = $this->sums($from, $to, $operatingUnitId, $excludeClosing);
        $accounts = Account::query()->orderBy('account_code')->get(['id', 'account_code', 'name', 'type', 'parent_account_id', 'status']);

        $children = [];
        foreach ($accounts as $account) {
            if ($account->parent_account_id !== null) {
                $children[$account->parent_account_id][] = $account->id;
            }
        }

        $depth = $this->depths($accounts->all());
        $rolled = [];
        $rollup = function (string $id) use (&$rollup, &$rolled, $sums, $children): array {
            if (isset($rolled[$id])) {
                return $rolled[$id];
            }

            $total = $sums[$id] ?? ['od' => 0.0, 'oc' => 0.0, 'pd' => 0.0, 'pc' => 0.0];
            foreach ($children[$id] ?? [] as $childId) {
                foreach ($rollup($childId) as $key => $value) {
                    $total[$key] += $value;
                }
            }

            return $rolled[$id] = $total;
        };

        $rows = [];
        foreach ($accounts as $account) {
            $isLeaf = ! isset($children[$account->id]);
            $hasDirectLines = isset($sums[$account->id]);
            $accountLevel = $depth[$account->id] ?? 1;

            $visible = match ($view) {
                // Leaves, plus any header that carries lines of its own (manual
                // entries on the 122 root) — otherwise the totals would not tie.
                'leaf' => $isLeaf || $hasDirectLines,
                'level' => $accountLevel === $level || ($isLeaf && $accountLevel < (int) $level),
                default => true,
            };

            if (! $visible) {
                continue;
            }

            $amounts = $view === 'leaf' ? ($sums[$account->id] ?? ['od' => 0.0, 'oc' => 0.0, 'pd' => 0.0, 'pc' => 0.0]) : $rollup($account->id);
            $row = $this->row($account, $amounts, $accountLevel, $isLeaf, $hasDirectLines);

            if (! $includeZero && $this->isZero($row)) {
                continue;
            }

            $rows[] = $row;
        }

        $totals = ['opening_debit' => 0.0, 'opening_credit' => 0.0, 'period_debit' => 0.0, 'period_credit' => 0.0, 'closing_debit' => 0.0, 'closing_credit' => 0.0];
        $openingNet = 0.0;
        $closingNet = 0.0;

        foreach ($sums as $amounts) {
            $opening = $amounts['od'] - $amounts['oc'];
            $closing = $opening + $amounts['pd'] - $amounts['pc'];
            $openingNet += $opening;
            $closingNet += $closing;
            $totals['opening_debit'] += max($opening, 0.0);
            $totals['opening_credit'] += max(-$opening, 0.0);
            $totals['period_debit'] += $amounts['pd'];
            $totals['period_credit'] += $amounts['pc'];
            $totals['closing_debit'] += max($closing, 0.0);
            $totals['closing_credit'] += max(-$closing, 0.0);
        }

        $totals = array_map(fn (float $v): float => round($v, 4), $totals);

        return [
            'from' => $from,
            'to' => $to,
            'view' => $view,
            'level' => $view === 'level' ? $level : null,
            'rows' => $rows,
            'totals' => $totals,
            // Legacy keys: the movement columns, which equal all-time totals when no `from` is given.
            'total_debit' => $totals['period_debit'],
            'total_credit' => $totals['period_credit'],
            'balanced' => abs($openingNet) < self::TOLERANCE
                && abs($totals['period_debit'] - $totals['period_credit']) < self::TOLERANCE
                && abs($closingNet) < self::TOLERANCE,
        ];
    }

    /**
     * @return array<string, array{od: float, oc: float, pd: float, pc: float}>
     */
    private function sums(?string $from, ?string $to, ?string $operatingUnitId, bool $excludeClosing): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_entries.deleted_at')
            ->groupBy('journal_lines.account_id')
            ->select('journal_lines.account_id');

        if ($from !== null) {
            $query->selectRaw('SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_lines.debit ELSE 0 END) as od', [$from])
                ->selectRaw('SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_lines.credit ELSE 0 END) as oc', [$from])
                ->selectRaw('SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.debit ELSE 0 END) as pd', [$from])
                ->selectRaw('SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_lines.credit ELSE 0 END) as pc', [$from]);
        } else {
            $query->selectRaw('0 as od, 0 as oc, SUM(journal_lines.debit) as pd, SUM(journal_lines.credit) as pc');
        }

        if ($to !== null) {
            $query->where('journal_entries.entry_date', '<', Carbon::parse($to)->addDay()->toDateString());
        }

        if ($operatingUnitId !== null) {
            $query->where('journal_lines.operating_unit_id', $operatingUnitId);
        }

        if ($excludeClosing) {
            $query->whereNotIn('journal_entries.id', JournalEntry::query()->closingRelated()->select('id'));
        }

        $sums = [];
        foreach ($query->get() as $row) {
            $sums[$row->account_id] = [
                'od' => round((float) $row->od, 4),
                'oc' => round((float) $row->oc, 4),
                'pd' => round((float) $row->pd, 4),
                'pc' => round((float) $row->pc, 4),
            ];
        }

        return $sums;
    }

    /**
     * @param  array{od: float, oc: float, pd: float, pc: float}  $amounts
     * @return array<string, mixed>
     */
    private function row(Account $account, array $amounts, int $level, bool $isLeaf, bool $hasDirectLines): array
    {
        $opening = round($amounts['od'] - $amounts['oc'], 4);
        $closing = round($opening + $amounts['pd'] - $amounts['pc'], 4);

        return [
            'account_id' => $account->id,
            'account_code' => $account->account_code,
            'name' => $account->name,
            'type' => $account->type,
            'level' => $level,
            'parent_account_id' => $account->parent_account_id,
            'is_leaf' => $isLeaf,
            'has_direct_lines' => $hasDirectLines,
            'opening_debit' => max($opening, 0.0),
            'opening_credit' => max(-$opening, 0.0),
            'opening_balance' => $opening,
            'period_debit' => round($amounts['pd'], 4),
            'period_credit' => round($amounts['pc'], 4),
            'closing_debit' => max($closing, 0.0),
            'closing_credit' => max(-$closing, 0.0),
            'closing_balance' => $closing,
            // Legacy keys, kept for existing callers.
            'debit' => round($amounts['pd'], 4),
            'credit' => round($amounts['pc'], 4),
            'balance' => $closing,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isZero(array $row): bool
    {
        return abs($row['opening_balance']) < self::TOLERANCE
            && abs($row['period_debit']) < self::TOLERANCE
            && abs($row['period_credit']) < self::TOLERANCE;
    }

    /**
     * Depth of each account in the chart, roots at 1.
     *
     * @param  list<Account>  $accounts
     * @return array<string, int>
     */
    private function depths(array $accounts): array
    {
        $parentOf = [];
        foreach ($accounts as $account) {
            $parentOf[$account->id] = $account->parent_account_id;
        }

        $depth = [];
        foreach (array_keys($parentOf) as $id) {
            $level = 1;
            $seen = [$id => true];
            $current = $parentOf[$id];
            while ($current !== null && isset($parentOf[$current]) && ! isset($seen[$current])) {
                $seen[$current] = true;
                $level++;
                $current = $parentOf[$current];
            }
            $depth[$id] = $level;
        }

        return $depth;
    }
}
