<?php

declare(strict_types=1);

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-01, TRS-05, TRS-06.
 *
 * Provisions leaf accounts:
 * - 125 (شيكات برسم التحصيل) under 12 (asset)
 * - 227 (أوراق الدفع) under 22 (liability)
 * - 44 (زيادة الخزائن) under 4 (revenue)
 * - 625 (عجز الخزائن) under 6 (expense)
 *
 * on existing databases via ChartOfAccountsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        // Account types and hierarchies updated here may carry postings.
    }
};
