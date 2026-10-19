<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The receivables subtree: account 122 and every descendant by parent_account_id.
 *
 * Computed per call (no static cache) so it never goes stale across requests
 * that add sub-accounts, or across tests.
 */
final class ReceivableAccounts
{
    public const ROOT_CODE = '122';

    /**
     * @return array<int, string>
     */
    public static function subtreeIds(): array
    {
        return AccountTree::subtreeIds(self::ROOT_CODE);
    }

    public static function contains(string $accountId): bool
    {
        return in_array($accountId, self::subtreeIds(), true);
    }
}
