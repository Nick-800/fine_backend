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
 * Scoped to what the implemented modules actually post: inventory, WIP, payables
 * and COGS. Overhead, fixed assets and FX accounts belong with the rest of
 * Phase 08 and are deliberately absent rather than seeded unused.
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
            ['1130', 'Finished Goods Inventory', 'asset', '1100'],
            ['1131', 'Finished Goods — Foam Blocks', 'asset', '1130'],
            ['1132', 'Finished Goods — Cut Pieces', 'asset', '1130'],
            ['1133', 'Finished Goods — Byproduct Fill', 'asset', '1130'],
            ['1200', 'Cash and Bank', 'asset', '1000'],
            ['1300', 'Accounts Receivable', 'asset', '1000'],

            ['2000', 'Liabilities', 'liability', null],
            ['2100', 'Accounts Payable', 'liability', '2000'],
            ['2200', 'Wages Payable', 'liability', '2000'],

            ['3000', 'Equity', 'equity', null],
            ['3100', 'Retained Earnings', 'equity', '3000'],

            ['4000', 'Revenue', 'revenue', null],
            ['4100', 'Sales Revenue', 'revenue', '4000'],

            ['5000', 'Expenses', 'expense', null],
            ['5100', 'Cost of Goods Sold', 'expense', '5000'],
            ['5200', 'Production Variance', 'expense', '5000'],
        ];

        $created = [];

        foreach ($accounts as [$code, $name, $type, $parentCode]) {
            $created[$code] = Account::create([
                'chart_of_accounts_id' => $coa->id,
                'account_code' => $code,
                'name' => $name,
                'type' => $type,
                'currency' => $company->default_currency ?? 'LYD',
                'parent_account_id' => $parentCode !== null ? $created[$parentCode]->id : null,
            ]);
        }
    }
}
