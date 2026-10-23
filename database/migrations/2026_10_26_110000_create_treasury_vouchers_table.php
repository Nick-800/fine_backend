<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-02: Receipt and payment vouchers (سندات القبض والصرف).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('voucher_number')->unique();
            $table->string('kind', 10);
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->string('counter_account_code', 20);
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->decimal('amount_lyd', 18, 4);
            $table->date('voucher_date');
            $table->string('payee', 150)->nullable();
            $table->string('description', 500);
            $table->string('status', 12)->default('posted');
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('cancel_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['cash_account_id', 'voucher_date']);
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_vouchers');
    }
};
