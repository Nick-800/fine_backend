<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            // Party dimension: lets receivables reports be built from the ledger.
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->index('client_id');
            $table->index(['account_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
