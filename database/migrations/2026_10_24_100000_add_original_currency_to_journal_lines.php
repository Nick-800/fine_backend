<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-15: Store original foreign currency details alongside the functional LYD amount.
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable()->after('credit');
            $table->decimal('original_amount', 18, 4)->nullable()->after('currency');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('original_amount');

            $table->index(['account_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'currency']);
            $table->dropColumn(['currency', 'original_amount', 'fx_rate']);
        });
    }
};
