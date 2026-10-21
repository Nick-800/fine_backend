<?php

declare(strict_types=1);

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 §8.4 / HR-08. Payroll posts as two entries:
 *   1. ACCRUAL: DR 57 (wages) / CR 226 (wages payable) + CR 225 (deductions)
 *      dated on the period month end.
 *   2. PAYMENT: DR 226 (wages payable) / CR treasury dated today.
 *
 * Adds payment_journal_entry_id to payroll_runs so the payment entry is linked
 * directly to the run, and provisions leaf account 226 (رواتب وأجور مستحقة)
 * on existing databases via ChartOfAccountsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->foreignUuid('payment_journal_entry_id')->nullable()->after('approved_by_user_id')->constrained('journal_entries')->restrictOnDelete();
        });

        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropForeign(['payment_journal_entry_id']);
            $table->dropColumn('payment_journal_entry_id');
        });
    }
};
