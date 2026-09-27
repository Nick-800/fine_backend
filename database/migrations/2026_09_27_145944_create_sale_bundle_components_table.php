<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a sold bundle actually contains, defined after checkout: each
        // row is an item, how many, and its size. The client's invoice shows
        // only the bundle; the delivery note and the cutter's sheet show these.
        Schema::create('sale_bundle_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items');
            $table->decimal('quantity', 12, 4);
            $table->decimal('length_m', 8, 3)->nullable();
            $table->decimal('width_m', 8, 3)->nullable();
            $table->decimal('height_m', 8, 3)->nullable();
            $table->decimal('volume_m3', 12, 6)->nullable();

            // Guidance only — what the item's own price makes this row worth.
            // The client pays the bundle price entered at the POS.
            $table->decimal('reference_price', 15, 4)->nullable();

            // pending → ready (taken from stock) | at_cutter → ready → delivered
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('cutter_work_order_line_id')->nullable()
                ->constrained('cutter_work_order_lines')->nullOnDelete();

            // Actual cost of the lots handed over — the COGS side, known at delivery.
            $table->decimal('unit_cost_actual', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // The lots set aside for a component. A cut piece is its own lot, so a
        // row of 4 pieces holds 4 allocations.
        Schema::create('sale_component_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_bundle_component_id')->constrained('sale_bundle_components')->cascadeOnDelete();
            $table->foreignUuid('stock_lot_id')->constrained('stock_lots');
            $table->decimal('quantity', 12, 4);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_component_allocations');
        Schema::dropIfExists('sale_bundle_components');
    }
};
