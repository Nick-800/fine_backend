<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        $company = \App\Models\Company::firstOrCreate(
            ['name' => 'Fine Test Co'],
            ['default_currency' => 'LYD'],
        );

        parent::run();

        if ($company === null) {
            return;
        }

        $coa = \App\Models\ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        // code, name, type, parent code
        $subAccounts = [
            ['1100', 'المخزون', 'asset', '1000'],
            ['1110', 'مخزون المواد الخام', 'asset', '1100'],
            ['1120', 'إنتاج تحت التشغيل', 'asset', '1100'],
            ['1121', 'إنتاج تحت التشغيل — مصنع الإسفنج', 'asset', '1120'],
            ['1122', 'إنتاج تحت التشغيل — قسم القص', 'asset', '1120'],
            ['1123', 'إنتاج تحت التشغيل — قسم الأثاث', 'asset', '1120'],
            ['1130', 'مخزون الإنتاج التام', 'asset', '1100'],
            ['1131', 'إنتاج تام — قوالب الإسفنج', 'asset', '1130'],
            ['1132', 'إنتاج تام — القطع المقصوصة', 'asset', '1130'],
            ['1133', 'إنتاج تام — حشوات وبقايا الإنتاج', 'asset', '1130'],
            ['1134', 'إنتاج تام — الأثاث والمفروشات', 'asset', '1130'],
            ['1200', 'النقدية وما في حكمها', 'asset', '1000'],
            ['1300', 'المدينون والعملاء', 'asset', '1000'],
            ['1400', 'الأصول الثابتة', 'asset', '1000'],
            ['1500', 'دفعات مقدمة للموردين', 'asset', '1000'],
            ['1450', 'مجمع الإهلاك المتراكم', 'asset', '1000'],

            ['2100', 'الدائنون والموردون', 'liability', '2000'],
            ['2200', 'الأجور والرواتب المستحقة', 'liability', '2000'],
            ['2210', 'استقطاعات الرواتب المستحقة', 'liability', '2000'],
            ['2300', 'وسيط تكاليف الاستيراد', 'liability', '2000'],

            ['3100', 'الأرباح المحتجزة', 'equity', '3000'],

            ['4100', 'إيرادات المبيعات', 'revenue', '4000'],
            ['4200', 'أرباح فروق أسعار الصرف', 'revenue', '4000'],
            ['4300', 'أرباح بيع واستبعاد أصول ثابتة', 'revenue', '4000'],

            ['5100', 'تكلفة البضاعة المباعة', 'expense', '5000'],
            ['5200', 'فروقات المخزون والتصنيع', 'expense', '5000'],
            ['5300', 'خسائر فروق أسعار الصرف', 'expense', '5000'],
            ['5400', 'المصروفات العامة والصناعية', 'expense', '5000'],
            ['5500', 'مصروف الإهلاك', 'expense', '5000'],
            ['5600', 'خسائر بيع واستبعاد أصول ثابتة', 'expense', '5000'],
            ['5700', 'مصروف الرواتب والأجور', 'expense', '5000'],
        ];

        $created = [];
        foreach (\App\Models\Account::orderBy('account_code')->get() as $existing) {
            $created[$existing->account_code] = $existing;
        }

        foreach ($subAccounts as [$code, $name, $type, $parentCode]) {
            $created[$code] = \App\Models\Account::updateOrCreate(
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
