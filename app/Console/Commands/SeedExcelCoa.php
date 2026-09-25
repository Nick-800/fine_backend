<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use Illuminate\Console\Command;

final class SeedExcelCoa extends Command
{
    protected $signature = 'coa:seed-excel
                            {--dataset=all : Specify which dataset to seed: foam, cutter, or all (foam primary)}
                            {--unit= : Alias for --dataset}
                            {--force : Force execution without confirmation}';

    protected $description = 'Seed the unified company Chart of Accounts extracted from the Excel datasets';

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

        $rawOption = $this->option('unit') ?: $this->option('dataset');
        $selectedDataset = strtolower((string) ($rawOption ?: 'all'));
        if (! in_array($selectedDataset, ['foam', 'cutter', 'all'], true)) {
            $this->error("Invalid --dataset option '{$selectedDataset}'. Allowed values: foam, cutter, all.");

            return self::FAILURE;
        }

        $globalParentIds = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->pluck('id', 'account_code')
            ->all();

        $chartsToLoad = [];
        $foamFile = database_path('seeders/data/foam_chart.php');
        $cutterFile = database_path('seeders/data/cutter_chart.php');

        if ($selectedDataset === 'all' || $selectedDataset === 'foam') {
            if (file_exists($foamFile)) {
                $chartsToLoad['foam'] = require $foamFile;
            } else {
                $this->warn("Data file not found: {$foamFile}");
            }
        }

        if ($selectedDataset === 'all' || $selectedDataset === 'cutter') {
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

        $this->info('Starting unified Chart of Accounts import from extracted Excel datasets...');
        $startTime = microtime(true);
        $tableRows = [];

        $existingByCode = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->pluck('id', 'account_code')
            ->all();

        // 1. Foam dataset is the primary master chart
        if (isset($chartsToLoad['foam'])) {
            [$created, $updated, $skipped] = $this->importDataset(
                $coa,
                $company,
                $chartsToLoad['foam'],
                $existingByCode,
                $globalParentIds,
                skipConflicts: false
            );
            $tableRows[] = ['foam', 'Master Factory Chart (مصنع الإسفنج)', count($chartsToLoad['foam']), $created, $updated, $skipped, 'SUCCESS'];
        }

        // 2. Secondary dataset: cutter (skips conflicting codes already defined in master chart)
        if (isset($chartsToLoad['cutter'])) {
            $isSecondary = isset($chartsToLoad['foam']);
            [$created, $updated, $skipped] = $this->importDataset(
                $coa,
                $company,
                $chartsToLoad['cutter'],
                $existingByCode,
                $globalParentIds,
                skipConflicts: $isSecondary
            );
            $status = $isSecondary ? 'MERGED (Duplicates Skipped)' : 'SUCCESS';
            $tableRows[] = ['cutter', 'Cutter Chart (مقص فاين)', count($chartsToLoad['cutter']), $created, $updated, $skipped, $status];
        }

        $this->newLine();
        $this->table(
            ['Dataset', 'Description', 'Rows in File', 'Created', 'Updated', 'Skipped', 'Status'],
            $tableRows,
        );

        $elapsed = round(microtime(true) - $startTime, 2);
        $totalAccounts = Account::where('chart_of_accounts_id', $coa->id)->count();
        $this->info("Unified Chart of Accounts seeding completed in {$elapsed}s. Total accounts in company chart: {$totalAccounts}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     * @param  array<string, string>  $existingByCode
     * @param  array<string, string>  $globalParentIds
     * @return array{0: int, 1: int, 2: int} [created, updated, skipped]
     */
    public function importDataset(
        ChartOfAccounts $coa,
        Company $company,
        array $rows,
        array &$existingByCode,
        array $globalParentIds,
        bool $skipConflicts = false
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $currency = $company->default_currency ?? 'LYD';
        $rowsToProcess = [];

        // Pass 1: Land accounts with parent_account_id = null
        foreach ($rows as [$code, $name, $section]) {
            if ($skipConflicts && isset($existingByCode[$code])) {
                $skipped++;

                continue;
            }

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
                    'account_code' => $code,
                ],
                $values,
            );

            $created += $account->wasRecentlyCreated ? 1 : 0;
            $updated += $account->wasRecentlyCreated ? 0 : 1;
            $existingByCode[$code] = $account->id;
            $rowsToProcess[] = [$code, $name, $section];
        }

        // Pass 2: Resolve parent accounts hierarchically
        $childrenAccounts = Account::query()
            ->where('chart_of_accounts_id', $coa->id)
            ->whereIn('account_code', array_column($rowsToProcess, 0))
            ->get()
            ->keyBy('account_code');

        foreach ($rowsToProcess as [$code, $name, $section]) {
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

        return [$created, $updated, $skipped];
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
}
