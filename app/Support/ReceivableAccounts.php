<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

/**
 * The receivables subtree: account 122 and every descendant by parent_account_id.
 *
 * Computed per call (no static cache) so it never goes stale across requests
 * that add sub-accounts, or across tests.
 */
final class ReceivableAccounts
{
    /**
     * @return array<int, string>
     */
    public static function subtreeIds(): array
    {
        $rootId = Account::query()->where('account_code', SalesAccounts::RECEIVABLE_FALLBACK)->value('id');

        if ($rootId === null) {
            return [];
        }

        $ids = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $frontier = Account::query()
                ->whereIn('parent_account_id', $frontier)
                ->pluck('id')
                ->diff($ids)
                ->values()
                ->all();

            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    public static function contains(string $accountId): bool
    {
        return in_array($accountId, self::subtreeIds(), true);
    }
}
