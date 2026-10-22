<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\JournalLine;

/**
 * Live treasury balances derived from the general ledger (TRS-01).
 *
 * A treasury's balance is the ledger balance of its linked leaf account;
 * the stored CashAccount.balance column is only the creation-time opening
 * figure.
 */
final class TreasuryBalanceService
{
    /**
     * Compute live ledger and currency balances for a collection of treasuries
     * in a single grouped query over posted journal lines (no N+1).
     *
     * @param  iterable<CashAccount>  $cashAccounts
     * @return array<string, array{ledger_balance: float, currency_balances: list<array{currency: string, original_balance: float, lyd_balance: float}>}>
     */
    public function balances(iterable $cashAccounts): array
    {
        $accounts = collect($cashAccounts);
        if ($accounts->isEmpty()) {
            return [];
        }

        $accountIds = $accounts->pluck('account_id')->filter()->unique()->values()->all();

        $byAccount = [];
        foreach ($accountIds as $accId) {
            $byAccount[$accId] = [
                'ledger_balance' => 0.0,
                'currency_balances' => [],
            ];
        }

        if (! empty($accountIds)) {
            $rows = JournalLine::query()
                ->posted()
                ->whereIn('account_id', $accountIds)
                ->selectRaw('
                    account_id,
                    currency,
                    SUM(debit - credit) as lyd_balance,
                    SUM(CASE WHEN debit > 0 THEN original_amount ELSE -original_amount END) as original_balance
                ')
                ->groupBy('account_id', 'currency')
                ->orderBy('currency')
                ->get();

            foreach ($rows as $row) {
                $accId = (string) $row->account_id;
                if (! isset($byAccount[$accId])) {
                    continue;
                }

                $lydBalance = round((float) $row->lyd_balance, 4);
                $byAccount[$accId]['ledger_balance'] = round($byAccount[$accId]['ledger_balance'] + $lydBalance, 4);

                if ($row->currency !== null) {
                    $byAccount[$accId]['currency_balances'][] = [
                        'currency' => (string) $row->currency,
                        'original_balance' => round((float) $row->original_balance, 4),
                        'lyd_balance' => $lydBalance,
                    ];
                }
            }
        }

        $results = [];
        foreach ($accounts as $cashAccount) {
            $accountId = $cashAccount->account_id;
            if ($accountId === null || ! isset($byAccount[$accountId])) {
                $results[$cashAccount->id] = [
                    'ledger_balance' => 0.0,
                    'currency_balances' => [],
                ];
            } else {
                $results[$cashAccount->id] = $byAccount[$accountId];
            }
        }

        return $results;
    }

    /**
     * Attach the live balances to each treasury's transient properties, for
     * CashAccountResource.
     *
     * @param  iterable<CashAccount>  $cashAccounts
     */
    public function attach(iterable $cashAccounts): void
    {
        $balances = $this->balances($cashAccounts);

        foreach ($cashAccounts as $cashAccount) {
            $cashAccount->ledger_balance = $balances[$cashAccount->id]['ledger_balance'];
            $cashAccount->currency_balances = $balances[$cashAccount->id]['currency_balances'];
        }
    }

    /**
     * Compute live ledger and currency balances for a single treasury.
     *
     * @return array{ledger_balance: float, currency_balances: list<array{currency: string, original_balance: float, lyd_balance: float}>}
     */
    public function balanceFor(CashAccount $cashAccount): array
    {
        return $this->balances([$cashAccount])[$cashAccount->id] ?? [
            'ledger_balance' => 0.0,
            'currency_balances' => [],
        ];
    }
}
