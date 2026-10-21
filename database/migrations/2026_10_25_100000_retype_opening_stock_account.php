<?php

declare(strict_types=1);

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Retype opening stock account 401 from revenue to asset under 13 (ACC-12).
 *
 * Moving opening stock to inventory assets ensures year-end closing entries do not
 * zero it out into retained earnings. Existing rows are updated in place by the
 * chart of accounts seeder; transaction history is untouched.
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
