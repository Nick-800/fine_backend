<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\OperatingUnit;

/**
 * The one place a sale's ledger accounts are chosen. Checkout, receivable
 * collection and delivery must agree, the same way InventoryAccounts keeps
 * intake and issue on one account.
 *
 * The fallbacks are the chart's section headers: 41 revenue, 51 cost of
 * goods sold, 122 customers. Receivables never fall back to 13 — that code is
 * inventory in the unified chart.
 */
final class SalesAccounts
{
    public const REVENUE_FALLBACK = '41';

    public const COGS = '51';

    public const RECEIVABLE_FALLBACK = '122';

    public static function revenueFor(OperatingUnit $unit): string
    {
        return $unit->revenueAccount?->account_code ?? self::REVENUE_FALLBACK;
    }

    public static function receivableFor(?Client $client): string
    {
        return $client?->account?->account_code ?? self::RECEIVABLE_FALLBACK;
    }

    /**
     * The ledger account behind a treasury or bank account. A treasury that is
     * not linked to the chart cannot take money — posting it to a header
     * account would hide it from every cash report.
     *
     * @throws SalesRuleException
     */
    public static function treasuryFor(CashAccount $cashAccount): string
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
}
