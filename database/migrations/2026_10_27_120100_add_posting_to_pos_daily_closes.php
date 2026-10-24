<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rule TRS-06: The POS daily close posts its cash difference to 625 (shortage) / 44 (surplus).
     */
    public function up(): void
    {
        Schema::table('pos_daily_closes', function (Blueprint $table): void {
            $table->foreignUuid('cash_account_id')->nullable()->after('closed_by_user_id')->constrained('cash_accounts')->nullOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->after('cash_account_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_daily_closes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('cash_account_id');
        });
    }
};
