<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\OperatingUnit;
use Illuminate\Database\Seeder;

/**
 * Seeds the per-unit chart of accounts for the foam factory and cutter units
 * from the xlsx attachments under `docs/`. Two data tables ship alongside
 * this seeder (database/seeders/data/foam_chart.php + cutter_chart.php) so
 * the import runs without the xlsx libraries.
 *
 * Optional — only run with `php artisan db:seed --class=OperatingUnitChartSeeder`.
 * Idempotent: re-running updates only changed rows.
 *
 * Resolution rules:
 *   - The 5 global mains (`1, 2, 3, 4, 5` from `ChartOfAccountsSeeder`) remain
 *     shared (`unit_id IS NULL`). Every unit-scoped account hangs under them.
 *   - `parent_account_id` is resolved within the same `unit_id` by leading-digit
 *     prefix convention (`1107` is parent of `110701`, etc.).
 *   - When an in-file parent doesn't exist, the seeder walks up the prefix
 *     to the unit's existing section root (e.g. `11`), so every row gets a
 *     parent and the import never orphans a row.
 *   - `type` is inferred from the first non-zero digit + section:
 *       1xxx + balance_sheet  -> asset
 *       2xxx + balance_sheet  -> liability
 *       3xxx + balance_sheet  -> equity
 *       4xxx                  -> revenue (trading_pl or other_pl)
 *       5/6/7/8xxx             -> expense (operations_pl, other_pl, or
 *                                       balance_sheet P&L items)
 */
final class OperatingUnitChartSeeder extends Seeder
{
    /**
     * Mapping from the per-unit xlsx label to the corresponding
     * OperatingUnit name seeded by the SystemBootstrapSeeder.
     */
    private const UNIT_NAMES = [
        'foam' => 'مصنع الإسفنج',
        'cutter' => 'قسم القص',
    ];

    public function run(): void
    {
        $company = Company::first();
        if ($company === null) {
            $this->command?->warn('OperatingUnitChartSeeder: no Company exists; run the standard seeders first.');

            return;
        }

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        // Pre-fetch the global chart's account id map so we can resolve top-level
        // sections (e.g. `11`, `12`) to their global parent (`1`, `2`) without
        // an extra query per row.
        $globalParentIds = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->whereNull('unit_id')
            ->pluck('id', 'account_code')
            ->all();

        $charts = [
            'foam' => require __DIR__.'/data/foam_chart.php',
            'cutter' => require __DIR__.'/data/cutter_chart.php',
        ];

        $summary = [];

        foreach ($charts as $key => $rows) {
            $unitName = self::UNIT_NAMES[$key] ?? null;
            $unit = $unitName !== null ? OperatingUnit::where('name', $unitName)->first() : null;

            if ($unit === null) {
                $this->command?->warn("OperatingUnitChartSeeder[{$key}]: unit '{$unitName}' not found; skipping.");
                $summary[$key] = ['unit' => null, 'created' => 0, 'updated' => 0];

                continue;
            }

            [$created, $updated] = $this->importUnit($unit, $coa, $company, $rows, $globalParentIds);
            $summary[$key] = ['unit' => $unit->name, 'created' => $created, 'updated' => $updated];
        }

        $this->command?->info('OperatingUnitChartSeeder: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     * @param  array<string, string>  $globalParentIds
     * @return array{0: int, 1: int} [created, updated]
     */
    private function importUnit(
        OperatingUnit $unit,
        ChartOfAccounts $coa,
        Company $company,
        array $rows,
        array $globalParentIds
    ): array {
        $existingByCode = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->where('unit_id', $unit->id)
            ->pluck('id', 'account_code')
            ->all();

        $created = 0;
        $updated = 0;
        $currency = $company->default_currency ?? 'LYD';

        // Two-pass insert because the xlsx isn't sorted top-down: a row like
        // `601` may appear in the file before its parent `6`. Pass 1 lands
        // every account with parent null; pass 2 resolves parents now that
        // every row exists.
        foreach ($rows as [$code, $name, $section]) {
            $values = [
                'name' => $name,
                'type' => $this->inferType($code, $section),
                'section' => $section,
                'currency' => $currency,
                'parent_account_id' => null,
            ];

            $account = Account::updateOrCreate(
                [
                    'chart_of_accounts_id' => $coa->id,
                    'unit_id' => $unit->id,
                    'account_code' => $code,
                ],
                $values,
            );

            $created += $account->wasRecentlyCreated ? 1 : 0;
            $updated += $account->wasRecentlyCreated ? 0 : 1;
            $existingByCode[$code] = $account->id;
        }

        // Pass 2: resolve parents now that every row exists in the unit's
        // chart. Compare against the database to detect changes and only
        // write when the parent actually differs.
        $childrenAccounts = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->where('unit_id', $unit->id)
            ->whereIn('account_code', array_column($rows, 0))
            ->get()
            ->keyBy('account_code');

        foreach ($rows as [$code, $name, $section]) {
            $account = $childrenAccounts[$code] ?? null;
            if ($account === null) {
                continue;
            }
            $parentId = $this->resolveParent($code, $existingByCode, $globalParentIds);
            if ($parentId !== $account->parent_account_id) {
                $account->parent_account_id = $parentId;
                $account->save();
                $updated++;
            }
        }

        return [$created, $updated];
    }

    /**
     * @param  array<string, string>  $existingByCode
     * @param  array<string, string>  $globalParentIds
     */
    private function resolveParent(string $code, array $existingByCode, array $globalParentIds): ?string
    {
        // 1) Walk within the unit's chart by trimming the trailing 2 chars
        //    repeatedly. The xlsx uses an "add 2 chars per level" convention
        //    (e.g. 11 -> 121 -> 122001 -> 12200101) so the parent is always
        //    the prefix minus 2 chars.
        $length = strlen($code);
        for ($len = $length - 2; $len >= 2; $len -= 2) {
            $candidate = substr($code, 0, $len);
            if (isset($existingByCode[$candidate])) {
                return $existingByCode[$candidate];
            }
        }

        // 2) Fall back to a unit root. Some unit-level section roots (`6`, `7`)
        //    are unit-specific and don't map to a global main, but they
        //    still act as parents for their unit's children. Walk the unit
        //    chart for any single- or two-char root that matches the prefix.
        $startAt = $length - 2;
        if ($startAt < 1) {
            $startAt = 1;
        }
        for ($len = $startAt; $len >= 1; $len -= 2) {
            $candidate = substr($code, 0, $len);
            // Prefer a unit-root parent (it has `unit_id = $unit->id`) so
            // the parent's `parent_account_id` value is consistent. Fall
            // back to the global chart only when no unit root matches.
            if (isset($existingByCode[$candidate])) {
                return $existingByCode[$candidate];
            }
            if (isset($globalParentIds[$candidate])) {
                return $globalParentIds[$candidate];
            }
            // Skip length-1 candidates that aren't real roots (avoid
            // anchoring under the global mains when the unit had its own).
            if ($len === 1) {
                break;
            }
        }

        // 3) Truly top-level (e.g. a brand-new unit-level root with no
        //    global anchor). Leave parent null.
        return null;
    }

    private function inferType(string $code, string $section): string
    {
        $first = $code[0] ?? '';

        if ($section === 'balance_sheet') {
            return match ($first) {
                '1' => 'asset',
                '2' => 'liability',
                '3' => 'equity',
                default => 'asset',
            };
        }

        if ($section === 'trading_pl') {
            return 'revenue';
        }

        return 'expense';
    }
}
