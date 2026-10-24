<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 Wave 2 / TRS-05: Customer cheques (الشيكات الواردة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('cheque_number_internal')->unique();
            $table->string('direction', 10)->default('incoming');
            $table->string('cheque_no', 50);
            $table->string('bank_name', 100);
            $table->string('drawer_name', 150)->nullable();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->default('LYD');
            $table->date('issue_date')->nullable();
            $table->date('due_date');
            $table->date('received_date');
            $table->boolean('is_certified')->default(false);
            $table->string('status', 15);
            $table->foreignUuid('deposit_cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('bounce_reason', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('client_id');
        });

        Schema::create('cheque_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cheque_id')->constrained('cheques')->cascadeOnDelete();
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15);
            $table->date('event_date');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['cheque_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_events');
        Schema::dropIfExists('cheques');
    }
};
