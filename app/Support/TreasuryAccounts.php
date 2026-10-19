<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;

/**
 * The one place a treasury or bank account is turned into its ledger code.
 * Every operation that moves cash — sales, collections, payroll, settlements,
 * overhead, fixed assets, cash stock intake — names the treasury it uses and
 * posts to the leaf account behind it, never to the 12/121 headers.
 */
final class TreasuryAccounts
{
    /**
     * A treasury that is not linked to the chart cannot move money — posting it
     * to a header account would hide it from every cash report.
     *
     * @throws SalesRuleException
     */
    public static function codeFor(CashAccount $cashAccount): string
    {
        $code = $cashAccount->account?->account_code;

        if ($code === null) {
            throw new SalesRuleException(
                "Treasury '{$cashAccount->name}' is not linked to a ledger account yet.",
                'TREASURY_NOT_LINKED',
            );
        }

        return $code;
    }

    /**
     * Resolve a treasury picked by id on a request.
     *
     * @throws SalesRuleException
     */
    public static function codeForId(?string $cashAccountId): string
    {
        $cashAccount = $cashAccountId !== null
            ? CashAccount::with('account')->find($cashAccountId)
            : null;

        if ($cashAccount === null) {
            throw new SalesRuleException('Choose the treasury the money comes from or goes into.', 'TREASURY_REQUIRED');
        }

        return self::codeFor($cashAccount);
    }
}
