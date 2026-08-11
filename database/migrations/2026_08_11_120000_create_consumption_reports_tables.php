<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumption_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_batch_id')->constrained('production_batches')->cascadeOnDelete();
            $table->timestamp('reported_at');
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            // One report per run: the machine reports its consumption once.
            $table->unique('production_batch_id');
        });

        Schema::create('consumption_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('consumption_report_id')->constrained('consumption_reports')->cascadeOnDelete();
            $table->foreignUuid('tank_stock_id')->nullable()->constrained('tank_stocks')->nullOnDelete();
            $table->foreignUuid('chemical_inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->decimal('quantity_consumed', 15, 4);

            // FOAM-04: the tank's weighted average at the moment of consumption.
            // Snapshotted because the tank's average moves on every later refill,
            // and this batch's cost must not move with it.
            $table->decimal('unit_cost_at_consumption', 15, 4);

            $table->timestamps();

            $table->index('chemical_inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_lines');
        Schema::dropIfExists('consumption_reports');
    }
};
