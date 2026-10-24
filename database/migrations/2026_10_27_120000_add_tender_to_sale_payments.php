<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-CHQ-033, BR-CHQ-015: POS cash tender and change calculation, plus
     * cheque collection linking.
     */
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->decimal('tendered_amount', 18, 4)->nullable()->after('amount');
            $table->decimal('change_given', 18, 4)->nullable()->after('tendered_amount');
            $table->foreignUuid('cheque_id')->nullable()->after('journal_entry_id')->constrained('cheques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cheque_id');
            $table->dropColumn(['change_given', 'tendered_amount']);
        });
    }
};
