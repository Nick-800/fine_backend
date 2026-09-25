<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use App\Models\OperatingUnit;
use Illuminate\Console\Command;

final class SeedExcelCoa extends Command
{
    protected $signature = 'coa:seed-excel
                            {--unit=all : Specify which unit chart to seed: foam, cutter, or all}
                            {--force : Force execution without confirmation}';

    protected $description = 'Seed the Chart of Accounts data extracted from the Excel files for foam and cutter units';

    /**
     * Mapping from unit key to its corresponding OperatingUnit name.
     */
    public const UNIT_NAMES = [
        'foam' => 'مصنع الإسفنج',
        'cutter' => 'قسم القص',
    ];

    public function handle(): int
    {
        $company = Company::first();
        if ($company === null) {
            $this->error('No Company found in database. Please run base migrations and seeders first.');

            return self::FAILURE;
        }

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        $selectedUnit = strtolower((string) $this->option('unit'));
        if (! in_array($selectedUnit, ['foam', 'cutter', 'all'], true)) {
            $this->error("Invalid --unit option '{$selectedUnit}'. Allowed values: foam, cutter, all.");

            return self::FAILURE;
        }

        $globalParentIds = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->whereNull('unit_id')
            ->pluck('id', 'account_code')
            ->all();

        $chartsToLoad = [];
        if ($selectedUnit === 'all' || $selectedUnit === 'foam') {
            $foamFile = database_path('seeders/data/foam_chart.php');
            if (file_exists($foamFile)) {
                $chartsToLoad['foam'] = require $foamFile;
            } else {
                $this->warn("Data file not found: {$foamFile}");
            }
        }

        if ($selectedUnit === 'all' || $selectedUnit === 'cutter') {
            $cutterFile = database_path('seeders/data/cutter_chart.php');
            if (file_exists($cutterFile)) {
                $chartsToLoad['cutter'] = require $cutterFile;
            } else {
                $this->warn("Data file not found: {$cutterFile}");
            }
        }

        if (empty($chartsToLoad)) {
            $this->error('No Excel Chart of Accounts data files found to import.');

            return self::FAILURE;
        }

        $this->info('Starting Chart of Accounts import from extracted Excel datasets...');
        $startTime = microtime(true);
        $tableRows = [];

        foreach ($chartsToLoad as $key => $rows) {
            $unit = $this->findOperatingUnit($key);

            if ($unit === null) {
                $expected = self::UNIT_NAMES[$key] ?? $key;
                $available = OperatingUnit::pluck('name')->implode(', ');
                $this->warn("Operating unit for '{$key}' ('{$expected}') not found. (Available in DB: {$available}). Skipping.");
                $tableRows[] = [$key, $expected, count($rows), 0, 0, 'SKIPPED (Unit Missing)'];

                continue;
            }

            [$created, $updated] = $this->importUnitChart($unit, $coa, $company, $rows, $globalParentIds);
            $tableRows[] = [$key, $unit->name, count($rows), $created, $updated, 'SUCCESS'];
        }

        $this->newLine();
        $this->table(
            ['Key', 'Unit Name', 'Rows in File', 'Created', 'Updated', 'Status'],
            $tableRows,
        );

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->info("Chart of Accounts seeding completed in {$elapsed}s.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     * @param  array<string, string>  $globalParentIds
     * @return array{0: int, 1: int} [created, updated]
     */
    public function importUnitChart(
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

        // Pass 1: Land every account with parent_account_id = null
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

        // Pass 2: Resolve parent accounts hierarchically
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
        $length = strlen($code);
        for ($len = $length - 2; $len >= 2; $len -= 2) {
            $candidate = substr($code, 0, $len);
            if (isset($existingByCode[$candidate])) {
                return $existingByCode[$candidate];
            }
        }

        $startAt = $length - 2;
        if ($startAt < 1) {
            $startAt = 1;
        }
        for ($len = $startAt; $len >= 1; $len -= 2) {
            $candidate = substr($code, 0, $len);
            if (isset($existingByCode[$candidate])) {
                return $existingByCode[$candidate];
            }
            if (isset($globalParentIds[$candidate])) {
                return $globalParentIds[$candidate];
            }
            if ($len === 1) {
                break;
            }
        }

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

    private function findOperatingUnit(string $key): ?OperatingUnit
    {
        $exactName = self::UNIT_NAMES[$key] ?? null;
        if ($exactName !== null) {
            $unit = OperatingUnit::where('name', $exactName)->first();
            if ($unit !== null) {
                return $unit;
            }
        }

        if ($key === 'foam') {
            return OperatingUnit::query()
                ->where(function ($q) {
                    $q->where('name', 'LIKE', '%إسفنج%')
                        ->orWhere('name', 'LIKE', '%اسفنج%')
                        ->orWhere('name', 'LIKE', '%foam%')
                        ->orWhere('code', 'LIKE', '%FOAM%');
                })
                ->first();
        }

        if ($key === 'cutter') {
            return OperatingUnit::query()
                ->where(function ($q) {
                    $q->where('name', 'LIKE', '%قص%')
                        ->orWhere('name', 'LIKE', '%cutter%')
                        ->orWhere('code', 'LIKE', '%CUT%');
                })
                ->first();
        }

        return null;
    }
}
