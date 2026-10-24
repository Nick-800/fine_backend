<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payable_settlements', function (Blueprint $table): void {
            $table->string('method', 10)->default('cash');
            $table->foreignUuid('cheque_id')->nullable()->constrained('cheques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payable_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cheque_id');
            $table->dropColumn('method');
        });
    }
};
