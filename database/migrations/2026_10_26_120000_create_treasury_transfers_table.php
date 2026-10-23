<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-03: Transfers between treasuries (التحويلات بين الخزائن).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('transfer_number')->unique();
            $table->foreignUuid('from_cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->foreignUuid('to_cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->decimal('amount_lyd', 18, 4)->nullable();
            $table->date('transfer_date');
            $table->string('notes', 500)->nullable();
            $table->string('status', 12)->default('draft');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_transfers');
    }
};
