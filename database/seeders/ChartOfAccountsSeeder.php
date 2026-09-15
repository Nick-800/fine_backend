<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Chart of accounts for the manufacturing flow.
 *
 * Scoped to what the implemented modules actually post: inventory, WIP,
 * payables, COGS, landed cost clearing, FX gain/loss, overhead and fixed
 * assets.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if ($company === null) {
            return;
        }

        $coa = ChartOfAccounts::create([
            'company_id' => $company->id,
            'name' => 'Main Chart of Accounts',
        ]);

        // code, name, type, parent code
        $accounts = [
            ['1000', 'Assets', 'asset', null],
            ['1100', 'Inventory', 'asset', '1000'],
            ['1110', 'Raw Material Inventory', 'asset', '1100'],
            ['1120', 'Work In Process', 'asset', '1100'],
            ['1121', 'WIP — Foam Production', 'asset', '1120'],
            ['1122', 'WIP — Cutter Production', 'asset', '1120'],
            ['1123', 'WIP — Furniture Production', 'asset', '1120'],
            ['1130', 'Finished Goods Inventory', 'asset', '1100'],
            ['1131', 'Finished Goods — Foam Blocks', 'asset', '1130'],
            ['1132', 'Finished Goods — Cut Pieces', 'asset', '1130'],
            ['1133', 'Finished Goods — Byproduct Fill', 'asset', '1130'],
            ['1134', 'Finished Goods — Furniture', 'asset', '1130'],
            ['1200', 'Cash and Bank', 'asset', '1000'],
            ['1300', 'Accounts Receivable', 'asset', '1000'],
            ['1400', 'Fixed Assets', 'asset', '1000'],
            // Import payments precede receipt: cash out sits here until the
            // order completes and moves the value into inventory.
            ['1500', 'Advances to Suppliers', 'asset', '1000'],
            // Contra-asset: carries a credit balance against 1400.
            ['1450', 'Accumulated Depreciation', 'asset', '1000'],

            ['2000', 'Liabilities', 'liability', null],
            ['2100', 'Accounts Payable', 'liability', '2000'],
            ['2200', 'Wages Payable', 'liability', '2000'],
            ['2210', 'Payroll Deductions Payable', 'liability', '2000'],
            ['2300', 'Landed Cost Clearing', 'liability', '2000'],

            ['3000', 'Equity', 'equity', null],
            ['3100', 'Retained Earnings', 'equity', '3000'],

            ['4000', 'Revenue', 'revenue', null],
            ['4100', 'Sales Revenue', 'revenue', '4000'],
            ['4200', 'FX Gain', 'revenue', '4000'],
            ['4300', 'Gain on Asset Disposal', 'revenue', '4000'],

            ['5000', 'Expenses', 'expense', null],
            ['5100', 'Cost of Goods Sold', 'expense', '5000'],
            ['5200', 'Inventory & Production Variance', 'expense', '5000'],
            ['5300', 'FX Loss', 'expense', '5000'],
            ['5400', 'Overhead Expense', 'expense', '5000'],
            ['5500', 'Depreciation Expense', 'expense', '5000'],
            ['5600', 'Loss on Asset Disposal', 'expense', '5000'],
            ['5700', 'Wage Expense', 'expense', '5000'],
        ];

        $created = [];

        foreach ($accounts as [$code, $name, $type, $parentCode]) {
            $created[$code] = Account::firstOrCreate(
                ['account_code' => $code],
                [
                    'chart_of_accounts_id' => $coa->id,
                    'name' => $name,
                    'type' => $type,
                    'currency' => $company->default_currency ?? 'LYD',
                    'parent_account_id' => $parentCode !== null ? $created[$parentCode]->id : null,
                ],
            );
        }
    }
}
