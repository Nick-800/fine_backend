<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            // Block classification: block (standard), separator (فاصل), head (بداية), or scrap (هدر).
            $table->string('block_type', 30)->default('block')->after('grade');
            $table->index('block_type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropIndex(['block_type']);
            $table->dropColumn('block_type');
        });
    }
};
