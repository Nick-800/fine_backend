<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-unit chart of accounts: a nullable foreign key to operating_units
     * so each unit can hang its own chart underneath the global 5 mains.
     *
     * `unit_id IS NULL` retains the existing global behavior (shared accounts
     * like the 5 mains and the Wave 4 standard set).
     * `unit_id = X` scopes the account to one operating unit; cross-unit
     * posting is refused at the service layer.
     *
     * `section` carries the reporting section (balance_sheet | trading_pl |
     * operations_pl | other_pl) from the xlsx attachments; nullable for the
     * existing global accounts which default at the service layer.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropUnique(['account_code']);
        });

        Schema::table('accounts', function (Blueprint $t) {
            $t->foreignUuid('unit_id')
                ->nullable()
                ->after('chart_of_accounts_id')
                ->constrained('operating_units')
                ->nullOnDelete();
            $t->string('section', 32)->nullable()->after('type');
            $t->unique(['chart_of_accounts_id', 'unit_id', 'account_code'], 'accounts_coa_unit_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropUnique('accounts_coa_unit_code_unique');
            $t->dropForeign(['unit_id']);
            $t->dropColumn(['unit_id', 'section']);
        });

        Schema::table('accounts', function (Blueprint $t) {
            $t->unique('account_code');
        });
    }
};
