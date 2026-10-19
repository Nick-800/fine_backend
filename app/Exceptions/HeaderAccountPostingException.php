<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Account;
use Exception;

/**
 * Raised when a posting names a header account — one that has sub-accounts.
 *
 * A balance on a header is invisible to every leaf-level report (treasury,
 * debtor statements, unit P&L), so the operation is refused before anything
 * is written rather than posted somewhere nobody looks.
 *
 * Surfaces as HTTP 422 with code `HEADER_ACCOUNT_POSTING`.
 */
final class HeaderAccountPostingException extends Exception
{
    public function __construct(public readonly Account $account)
    {
        parent::__construct(
            "Account {$account->account_code} ({$account->name}) is a header account with sub-accounts; post to one of its sub-accounts instead."
        );
    }
}
