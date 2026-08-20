<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_daily_closes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->date('close_date');
            // Expected is derived server-side from the day's POS cash sales;
            // counted is what the cashier physically found in the drawer.
            $table->decimal('expected_cash', 15, 4);
            $table->decimal('counted_cash', 15, 4);
            $table->decimal('difference', 15, 4);
            $table->unsignedInteger('sales_count');
            $table->decimal('total_sales', 15, 4);
            $table->string('notes')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['operating_unit_id', 'close_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_daily_closes');
    }
};
