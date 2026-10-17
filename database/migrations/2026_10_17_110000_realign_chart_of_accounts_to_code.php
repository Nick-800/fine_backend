<?php

declare(strict_types=1);

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Existing databases were seeded with the inline catalog overwritten by the
 * retired chart_of_accounts.json, so codes the services post to (12, 122, 221,
 * 43, 51, 111, 1121, 1131…) carried the JSON's names, types and parents. Re-run
 * the seeder so every code means what the code says.
 *
 * Accounts that have journal lines or are linked from clients, treasuries,
 * items, units, assets or POs are kept; only unused legacy accounts are
 * removed. Fresh databases (no company yet) are left for the normal seed.
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
        // Renames and re-parenting are not reversible; the retired JSON is gone.
    }
};
