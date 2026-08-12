<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MissingAccountException;
use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingService
{
    /**
     * Rounding tolerance for the balance check.
     *
     * Amounts are stored to 4dp and apportionment divides across many blocks, so
     * an exact float comparison would reject entries that are correct to the
     * stored precision.
     */
    private const BALANCE_TOLERANCE = 0.0001;

    /**
     * Post a balanced double-entry journal.
     *
     * ACC-01 is enforced here rather than in a model observer so an unbalanced
     * entry never reaches the database at all — a half-written journal is worse
     * than a rejected one, because it silently breaks the trial balance and
     * nothing downstream would notice.
     *
     * @param  array<int, array{account_code: string, debit?: float, credit?: float, operating_unit_id?: string|null, memo?: string|null}>  $lines
     */
    public function postJournal(
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $companyId = null,
        bool $isManual = false,
        ?string $userId = null,
        ?string $entryDate = null,
    ): JournalEntry {
        if ($lines === []) {
            throw new InvalidArgumentException('A journal entry needs at least one line.');
        }

        $companyId ??= Company::query()->value('id');

        if ($companyId === null) {
            throw new InvalidArgumentException('No company exists to post a journal against.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Journal amounts cannot be negative; use the opposite side instead.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line is either a debit or a credit, never both.');
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        $totalDebit = round($totalDebit, 4);
        $totalCredit = round($totalCredit, 4);

        if (abs($totalDebit - $totalCredit) > self::BALANCE_TOLERANCE) {
            throw new UnbalancedJournalException(
                "Journal does not balance: debits {$totalDebit} against credits {$totalCredit} for \"{$description}\"."
            );
        }

        if ($totalDebit === 0.0) {
            throw new InvalidArgumentException('A journal entry with no value has nothing to record.');
        }

        $entryDate ??= now()->toDateString();

        return DB::transaction(function () use ($description, $lines, $sourceType, $sourceId, $companyId, $isManual, $userId, $entryDate): JournalEntry {
            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'reference' => $this->nextReference($entryDate),
                'entry_date' => $entryDate,
                'description' => $description,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'is_manual' => $isManual,
                'created_by_user_id' => $userId,
            ]);

            foreach ($lines as $line) {
                $account = $this->resolveAccount($line['account_code']);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'operating_unit_id' => $line['operating_unit_id'] ?? null,
                    'debit' => round((float) ($line['debit'] ?? 0), 4),
                    'credit' => round((float) ($line['credit'] ?? 0), 4),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry->fresh(['lines.account']);
        });
    }

    /**
     * Trial balance across all accounts.
     *
     * If this ever fails to balance, something bypassed postJournal — which is
     * exactly what it is here to reveal.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_debit: float, total_credit: float, balanced: bool}
     */
    public function trialBalance(?string $operatingUnitId = null): array
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->selectRaw('accounts.account_code, accounts.name, accounts.type')
            ->selectRaw('SUM(journal_lines.debit) as total_debit')
            ->selectRaw('SUM(journal_lines.credit) as total_credit')
            ->groupBy('accounts.account_code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.account_code');

        if ($operatingUnitId !== null) {
            $query->where('journal_lines.operating_unit_id', $operatingUnitId);
        }

        $rows = $query->get()->map(fn ($row) => [
            'account_code' => $row->account_code,
            'name' => $row->name,
            'type' => $row->type,
            'debit' => round((float) $row->total_debit, 4),
            'credit' => round((float) $row->total_credit, 4),
            'balance' => round((float) $row->total_debit - (float) $row->total_credit, 4),
        ])->all();

        $totalDebit = round(array_sum(array_column($rows, 'debit')), 4);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 4);

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < self::BALANCE_TOLERANCE,
        ];
    }

    private function resolveAccount(string $code): Account
    {
        $account = Account::where('account_code', $code)->first();

        if ($account === null) {
            throw new MissingAccountException(
                "The chart of accounts has no account {$code}. Seed the chart of accounts before posting."
            );
        }

        return $account;
    }

    /**
     * The sequence year follows the entry date, not the posting date — a
     * backdated correction must count against its own year or two of them
     * would collide on the same reference.
     */
    private function nextReference(string $entryDate): string
    {
        $year = Carbon::parse($entryDate)->format('Y');
        $sequence = JournalEntry::withTrashed()->whereYear('entry_date', $year)->count() + 1;

        return sprintf('JE-%s-%05d', $year, $sequence);
    }
}
