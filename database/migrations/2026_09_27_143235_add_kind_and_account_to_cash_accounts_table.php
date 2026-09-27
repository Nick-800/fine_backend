<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table): void {
            // cash = a treasury/drawer, bank = a bank account.
            $table->string('kind', 10)->default('cash')->after('name');

            // The ledger account money received into this treasury is debited
            // to (e.g. 121102 خزينة مقص فاين). Required before a sale can use it.
            $table->foreignUuid('account_id')->nullable()->after('kind')
                ->constrained('accounts')->nullOnDelete();

            $table->index(['operating_unit_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table): void {
            $table->dropIndex(['operating_unit_id', 'kind']);
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('kind');
        });
    }
};
