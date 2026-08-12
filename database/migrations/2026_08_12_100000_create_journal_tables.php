<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference')->unique();
            $table->date('entry_date');
            $table->string('description');

            // ACC-03: what business event caused this entry. Required on
            // auto-posted entries so every figure traces back to its source.
            $table->string('source_document_type')->nullable();
            $table->uuid('source_document_id')->nullable();

            // ACC-04: manual entries exist for corrections and are marked as such,
            // so they can be reviewed separately from machine-generated ones.
            $table->boolean('is_manual')->default(false);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_document_type', 'source_document_id'], 'je_source_doc_index');
            $table->index('entry_date');
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();

            // Which unit the figure belongs to, so per-unit subledgers (ACC-05)
            // are derivable from lines without a separate table.
            $table->foreignUuid('operating_unit_id')->nullable()->constrained('operating_units')->nullOnDelete();

            // Debit and credit kept as separate columns rather than one signed
            // amount: it is how accountants read a ledger, and it makes the
            // balance check a plain sum comparison.
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('operating_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
