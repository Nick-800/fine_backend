<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ChartOfAccounts;
use App\Models\Company;

/**
 * Test-only seeder. Layers the standard 31 sub-accounts on top of the 5
 * mains that `ChartOfAccountsSeeder` creates.
 *
 * Production installs should use `ChartOfAccountsSeeder` directly so the
 * chart starts with just the 5 main accounts; sub-accounts are added
 * through the API as modules that need them come online.
 *
 * Tests that exercise modules posting to specific codes (1110, 1500, 2100,
 * etc.) call this seeder instead.
 */
class ChartOfAccountsTestSeeder extends ChartOfAccountsSeeder
{
    public function run(): void
    {
        // The parent seeder bails out if no Company exists; tests that seed
        // the chart directly without going through SystemBootstrapSeeder need
        // a Company to anchor the chart of accounts.
        $company = Company::firstOrCreate(
            ['name' => 'Fine Test Co'],
            ['default_currency' => 'LYD'],
        );

        parent::run();

        if ($company === null) {
            return;
        }

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        // code, name, type, parent code
        $subAccounts = [
            ['11', 'المخزون', 'asset', '1'],
            ['111', 'مخزون المواد الخام', 'asset', '11'],
            ['112', 'إنتاج تحت التشغيل', 'asset', '11'],
            ['1121', 'إنتاج تحت التشغيل — مصنع الإسفنج', 'asset', '112'],
            ['1122', 'إنتاج تحت التشغيل — قسم القص', 'asset', '112'],
            ['1123', 'إنتاج تحت التشغيل — قسم الأثاث', 'asset', '112'],
            ['113', 'مخزون الإنتاج التام', 'asset', '11'],
            ['1131', 'إنتاج تام — قوالب الإسفنج', 'asset', '113'],
            ['1132', 'إنتاج تام — القطع المقصوصة', 'asset', '113'],
            ['1133', 'إنتاج تام — حشوات وبقايا الإنتاج', 'asset', '113'],
            ['1134', 'إنتاج تام — الأثاث والمفروشات', 'asset', '113'],
            ['12', 'النقدية وما في حكمها', 'asset', '1'],
            ['13', 'المدينون والعملاء', 'asset', '1'],
            ['14', 'الأصول الثابتة', 'asset', '1'],
            ['15', 'دفعات مقدمة للموردين', 'asset', '1'],
            ['145', 'مجمع الإهلاك المتراكم', 'asset', '1'],

            ['21', 'الدائنون والموردون', 'liability', '2'],
            ['22', 'الأجور والرواتب المستحقة', 'liability', '2'],
            ['221', 'استقطاعات الرواتب المستحقة', 'liability', '2'],
            ['23', 'وسيط تكاليف الاستيراد', 'liability', '2'],

            ['31', 'الأرباح المحتجزة', 'equity', '3'],

            ['41', 'إيرادات المبيعات', 'revenue', '4'],
            ['42', 'أرباح فروق أسعار الصرف', 'revenue', '4'],
            ['43', 'أرباح بيع واستبعاد أصول ثابتة', 'revenue', '4'],

            ['51', 'تكلفة البضاعة المباعة', 'expense', '5'],
            ['52', 'فروقات المخزون والتصنيع', 'expense', '5'],
            ['53', 'خسائر فروق أسعار الصرف', 'expense', '5'],
            ['54', 'المصروفات العامة والصناعية', 'expense', '5'],
            ['55', 'مصروف الإهلاك', 'expense', '5'],
            ['56', 'خسائر بيع واستبعاد أصول ثابتة', 'expense', '5'],
            ['57', 'مصروف الرواتب والأجور', 'expense', '5'],
        ];

        $created = [];
        foreach (Account::orderBy('account_code')->get() as $existing) {
            $created[$existing->account_code] = $existing;
        }

        foreach ($subAccounts as [$code, $name, $type, $parentCode]) {
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
