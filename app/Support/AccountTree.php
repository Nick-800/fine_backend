<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

/**
 * Walks the chart by parent_account_id. Computed per call (no static cache) so
 * it never goes stale across requests that add sub-accounts, or across tests.
 */
final class AccountTree
{
    /**
     * The account with this code and every descendant.
     *
     * @return array<int, string>
     */
    public static function subtreeIds(string $rootCode): array
    {
        $rootId = Account::query()->where('account_code', $rootCode)->value('id');

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
}
