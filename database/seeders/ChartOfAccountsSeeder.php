<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Chart of accounts seed for the manufacturing flow.
 *
 * Production seed (this class) creates the **5 main accounts only**:
 * Assets, Liabilities, Equity, Revenue, Expenses. Sub-accounts are added
 * by the operator via `POST /accounts` as the modules that post against
 * them come online.
 *
 * Test seed (`ChartOfAccountsTestSeeder`) layers the standard sub-account
 * set on top of these 5 mains so feature tests can exercise modules that
 * post to specific codes (1110, 1500, 2100, 2300, 5200, etc.).
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if ($company === null) {
            return;
        }

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        if ($coa->name !== 'دليل الحسابات الرئيسي') {
            $coa->update(['name' => 'دليل الحسابات الرئيسي']);
        }

        // code, name, type, parent code
        $accounts = [
            ['1', 'الأصول', 'asset', null],
            ['2', 'الالتزامات', 'liability', null],
            ['3', 'حقوق الملكية', 'equity', null],
            ['4', 'الإيرادات', 'revenue', null],
            ['5', 'المصروفات والتكاليف', 'expense', null],
        ];

        $created = [];

        foreach ($accounts as [$code, $name, $type, $parentCode]) {
            $created[$code] = Account::updateOrCreate(
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
