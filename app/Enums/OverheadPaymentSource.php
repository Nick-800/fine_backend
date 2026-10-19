<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\TreasuryAccounts;

enum OverheadPaymentSource: string
{
    case Cash = 'cash';
    case Payable = 'payable';

    /**
     * The account the payment is credited to: the treasury the cash left from,
     * or Accounts Payable for a purchase on credit.
     *
     * @param  string|null  $cashAccountId  the treasury picked for a cash payment
     */
    public function accountCode(?string $cashAccountId = null): string
    {
        return match ($this) {
            self::Cash => TreasuryAccounts::codeForId($cashAccountId),
            self::Payable => '21', // Accounts Payable
        };
    }
}
