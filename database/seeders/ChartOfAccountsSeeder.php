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

        $coa = ChartOfAccounts::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'دليل الحسابات الرئيسي'],
        );

        if ($coa->name !== 'دليل الحسابات الرئيسي') {
            $coa->update(['name' => 'دليل الحسابات الرئيسي']);
        }

        // code, name, type, parent code
        $accounts = [
            ['1000', 'الأصول', 'asset', null],
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
            // Import payments precede receipt: cash out sits here until the
            // order completes and moves the value into inventory.
            ['1500', 'دفعات مقدمة للموردين', 'asset', '1000'],
            // Contra-asset: carries a credit balance against 1400.
            ['1450', 'مجمع الإهلاك المتراكم', 'asset', '1000'],

            ['2000', 'الالتزامات', 'liability', null],
            ['2100', 'الدائنون والموردون', 'liability', '2000'],
            ['2200', 'الأجور والرواتب المستحقة', 'liability', '2000'],
            ['2210', 'استقطاعات الرواتب المستحقة', 'liability', '2000'],
            ['2300', 'وسيط تكاليف الاستيراد', 'liability', '2000'],

            ['3000', 'حقوق الملكية', 'equity', null],
            ['3100', 'الأرباح المحتجزة', 'equity', '3000'],

            ['4000', 'الإيرادات', 'revenue', null],
            ['4100', 'إيرادات المبيعات', 'revenue', '4000'],
            ['4200', 'أرباح فروق أسعار الصرف', 'revenue', '4000'],
            ['4300', 'أرباح بيع واستبعاد أصول ثابتة', 'revenue', '4000'],

            ['5000', 'المصروفات والتكاليف', 'expense', null],
            ['5100', 'تكلفة البضاعة المباعة', 'expense', '5000'],
            ['5200', 'فروقات المخزون والتصنيع', 'expense', '5000'],
            ['5300', 'خسائر فروق أسعار الصرف', 'expense', '5000'],
            ['5400', 'المصروفات العامة والصناعية', 'expense', '5000'],
            ['5500', 'مصروف الإهلاك', 'expense', '5000'],
            ['5600', 'خسائر بيع واستبعاد أصول ثابتة', 'expense', '5000'],
            ['5700', 'مصروف الرواتب والأجور', 'expense', '5000'],
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
