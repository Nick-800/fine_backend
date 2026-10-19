<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Models\OperatingUnit;

/**
 * The default sales account of each selling unit. A sale from a unit with no
 * revenue account is refused (UNIT_REVENUE_NOT_LINKED), so installs get these
 * links on seed and on migration; the owner can change them on the unit page.
 */
final class UnitRevenueAccounts
{
    /**
     * @var array<string, string> unit name => revenue leaf code
     */
    public const DEFAULTS = [
        'مصنع الإسفنج' => '40201',
        'قسم القص' => '40202',
        'قسم الأثاث' => '40203',
        'صالة العرض' => '40204',
    ];

    /**
     * Link every known unit that has no revenue account yet. Units already
     * linked — by hand or earlier — are left alone.
     */
    public static function linkDefaults(): void
    {
        foreach (self::DEFAULTS as $unitName => $code) {
            $accountId = Account::query()->where('account_code', $code)->value('id');

            if ($accountId === null) {
                continue;
            }

            OperatingUnit::withoutGlobalScopes()
                ->where('name', $unitName)
                ->whereNull('revenue_account_id')
                ->update(['revenue_account_id' => $accountId]);
        }
    }
}
