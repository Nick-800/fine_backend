<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An account statement (FR-FREP-004): the balance brought forward before
 * `from`, every posted line in the period with the running balance after it,
 * and the closing balance.
 *
 * The running balance is a window sum over the whole period, computed before
 * search and pagination, so page 2 continues where page 1 stopped and a search
 * only hides lines — it never changes a balance. Balances are signed
 * debit-minus-credit; `normal_side` tells the client how to present them.
 */
final class AccountLedgerService
{
    public const EXPORT_LIMIT = 20000;

    /**
     * @param  list<string>  $accountIds
     * @return array{data: list<array<string, mixed>>, summary: array<string, mixed>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function statement(
        Account $account,
        array $accountIds,
        ?string $from,
        ?string $to,
        ?string $operatingUnitId,
        ?string $search,
        string $order = 'asc',
        int $perPage = 25,
        int $page = 1,
        bool $all = false,
        ?string $currency = null,
    ): array {
        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : null;

        $debitExpr = $currency !== null
            ? 'CASE WHEN journal_lines.debit > 0 THEN journal_lines.original_amount ELSE 0 END'
            : 'journal_lines.debit';
        $creditExpr = $currency !== null
            ? 'CASE WHEN journal_lines.credit > 0 THEN journal_lines.original_amount ELSE 0 END'
            : 'journal_lines.credit';

        $opening = 0.0;

        if ($from !== null) {
            $row = $this->base($accountIds, $operatingUnitId)
                ->when($currency !== null, fn (Builder $q) => $q->where('journal_lines.currency', $currency))
                ->where('journal_entries.entry_date', '<', $from)
                ->selectRaw("COALESCE(SUM({$debitExpr}), 0) as debit, COALESCE(SUM({$creditExpr}), 0) as credit")
                ->first();
            $opening = round((float) $row->debit - (float) $row->credit, 4);
        }

        $cumulativeExpr = $currency !== null
            ? "SUM(({$debitExpr}) - ({$creditExpr})) OVER (ORDER BY journal_entries.entry_date, journal_entries.created_at, journal_entries.reference, journal_lines.id) as cumulative"
            : 'SUM(journal_lines.debit - journal_lines.credit) OVER (ORDER BY journal_entries.entry_date, journal_entries.created_at, journal_entries.reference, journal_lines.id) as cumulative';

        $period = $this->base($accountIds, $operatingUnitId)
            ->when($currency !== null, fn (Builder $q) => $q->where('journal_lines.currency', $currency))
            ->select([
                'journal_lines.id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.currency',
                'journal_lines.original_amount',
                'journal_lines.fx_rate',
                'journal_lines.memo',
                'journal_entries.entry_date',
                'journal_entries.created_at as entry_created_at',
                'journal_entries.reference',
                'journal_entries.description',
            ])
            ->selectRaw($cumulativeExpr);

        if ($from !== null) {
            $period->where('journal_entries.entry_date', '>=', $from);
        }

        if ($to !== null) {
            $period->where('journal_entries.entry_date', '<', Carbon::parse($to)->addDay()->toDateString());
        }

        $sums = DB::query()->fromSub($this->base($accountIds, $operatingUnitId)
            ->when($currency !== null, fn (Builder $q) => $q->where('journal_lines.currency', $currency))
            ->when($from !== null, fn (Builder $q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('journal_entries.entry_date', '<', Carbon::parse($to)->addDay()->toDateString()))
            ->selectRaw("{$debitExpr} as debit, {$creditExpr} as credit"), 'p')
            ->selectRaw('COALESCE(SUM(p.debit), 0) as debit, COALESCE(SUM(p.credit), 0) as credit')
            ->first();

        $periodDebit = round((float) $sums->debit, 4);
        $periodCredit = round((float) $sums->credit, 4);

        $lines = DB::query()->fromSub($period, 'l');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $lines->where(fn (Builder $q) => $q->where('l.reference', 'like', $term)
                ->orWhere('l.description', 'like', $term)
                ->orWhere('l.memo', 'like', $term));
        }

        $direction = $order === 'desc' ? 'desc' : 'asc';
        $lines->orderBy('l.entry_date', $direction)
            ->orderBy('l.entry_created_at', $direction)
            ->orderBy('l.reference', $direction)
            ->orderBy('l.id', $direction);

        $total = (clone $lines)->count();

        if ($all) {
            if ($total > self::EXPORT_LIMIT) {
                throw new LedgerRuleException(
                    'Too many lines to export at once; narrow the date range.',
                    'LEDGER_EXPORT_TOO_LARGE',
                    ['limit' => self::EXPORT_LIMIT, 'total' => $total],
                );
            }
            $perPage = max($total, 1);
            $page = 1;
        }

        $pageRows = $lines->forPage($page, $perPage)->get(['l.id', 'l.cumulative']);

        return [
            'data' => $this->hydrate($pageRows, $opening),
            'summary' => [
                'opening_balance' => $opening,
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_balance' => round($opening + $periodDebit - $periodCredit, 4),
                'normal_side' => in_array($account->type, ['asset', 'expense'], true) ? 'debit' : 'credit',
                ...($currency !== null ? ['currency' => $currency] : []),
            ],
            'current_page' => $page,
            'last_page' => max((int) ceil($total / max($perPage, 1)), 1),
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * Signed balance (debit minus credit) of the accounts' posted lines dated
     * before the given day.
     *
     * @param  list<string>  $accountIds
     */
    public function balanceBefore(array $accountIds, string $date, ?string $operatingUnitId): float
    {
        $row = $this->base($accountIds, $operatingUnitId)
            ->where('journal_entries.entry_date', '<', $date)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        return round((float) $row->debit - (float) $row->credit, 4);
    }

    /**
     * @param  list<string>  $accountIds
     */
    private function base(array $accountIds, ?string $operatingUnitId): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->when($operatingUnitId !== null, fn (Builder $q) => $q->where('journal_lines.operating_unit_id', $operatingUnitId));
    }

    /**
     * Load the page's lines with their entry, unit and account, in page order.
     *
     * @param  Collection<int, object>  $pageRows
     * @return list<array<string, mixed>>
     */
    private function hydrate(Collection $pageRows, float $opening): array
    {
        $models = JournalLine::query()
            ->with([
                'journalEntry:id,reference,entry_date,description,is_manual,entry_type,reversal_of_id,source_document_type',
                'operatingUnit:id,name',
                'account:id,account_code,name',
            ])
            ->whereIn('id', $pageRows->pluck('id'))
            ->get()
            ->keyBy('id');

        return $pageRows->map(fn (object $row): array => [
            ...$models[$row->id]->toArray(),
            'running_balance' => round($opening + (float) $row->cumulative, 4),
        ])->values()->all();
    }
}
