<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\SalesRuleException;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\OperatingUnit;
use App\Services\CoaLinkService;

/**
 * The one place a sale's ledger accounts are chosen. Checkout, receivable
 * collection and delivery must agree, the same way InventoryAccounts keeps
 * intake and issue on one account.
 *
 * Every account returned is a leaf: revenue is the unit's own sales account,
 * a receivable is the client's own sub-account under 122. Section headers
 * (41, 122) are never posted to — AccountingService refuses them.
 */
final class SalesAccounts
{
    public const COGS = '51';

    /**
     * @throws SalesRuleException
     */
    public static function revenueFor(OperatingUnit $unit): string
    {
        $code = $unit->revenueAccount?->account_code;

        if ($code === null) {
            throw new SalesRuleException(
                "Operating unit '{$unit->name}' has no sales revenue account linked yet.",
                'UNIT_REVENUE_NOT_LINKED',
            );
        }

        return $code;
    }

    /**
     * The client's own receivable sub-account. A client created before every
     * client got one is given its sub-account here, on first use, so a credit
     * sale never lands on the 122 header.
     *
     * @throws SalesRuleException
     */
    public static function receivableFor(?Client $client): string
    {
        if ($client === null) {
            throw new SalesRuleException('A receivable needs the client it is owed by.', 'CLIENT_REQUIRED');
        }

        $code = $client->account?->account_code;

        if ($code !== null) {
            return $code;
        }

        $client->account_id = app(CoaLinkService::class)->provisionNextSubAccount(
            ReceivableAccounts::ROOT_CODE,
            'عميل - '.($client->entity?->name ?? $client->id),
        );
        $client->save();

        return (string) $client->load('account')->account?->account_code;
    }

    /**
     * @throws SalesRuleException
     */
    public static function treasuryFor(CashAccount $cashAccount): string
    {
        return TreasuryAccounts::codeFor($cashAccount);
    }
}
