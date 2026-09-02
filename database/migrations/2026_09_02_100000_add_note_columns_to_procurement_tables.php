<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->text('extra_allocation_note')->nullable()->after('fx_rate_used');
        });

        Schema::table('landed_cost_lines', function (Blueprint $table) {
            $table->text('note')->nullable()->after('is_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('extra_allocation_note');
        });

        Schema::table('landed_cost_lines', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
