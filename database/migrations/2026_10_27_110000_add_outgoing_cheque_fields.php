<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-05: Outgoing cheques (الشيكات الصادرة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table): void {
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('payee_name', 150)->nullable();
            $table->string('counter_account_code', 20)->nullable();
            $table->foreignUuid('issue_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issue_journal_entry_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['payee_name', 'counter_account_code']);
        });
    }
};
