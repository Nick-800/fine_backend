<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-04: Bank reconciliation tables (التسوية المصرفية).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 4);
            $table->string('status', 10)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('finished_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['cash_account_id', 'status']);
            $table->index(['cash_account_id', 'statement_date']);
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignUuid('journal_line_id')->unique()->constrained('journal_lines')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
