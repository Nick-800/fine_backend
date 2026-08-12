<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutter_work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();

            // Null client means an internal order from another unit. Internal
            // orders skip the credit check but use the same order model.
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();

            $table->string('order_number')->unique();
            $table->string('status', 50)->default('requested');

            // Material value pulled out of consumed blocks and held until the
            // order completes and it moves into output inventory.
            $table->decimal('wip_cost', 15, 4)->default(0);

            $table->text('notes')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
        });

        Schema::create('cutter_work_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cutter_work_order_id')->constrained('cutter_work_orders')->cascadeOnDelete();

            // The shape the client asked for, in their words. Appears on quotes
            // and invoices, and is never used for costing.
            $table->string('requested_spec');
            $table->unsignedInteger('quantity')->default(1);

            // The bounding box actually cut, and the only thing costing and
            // inventory use (CUT-05). Kept separate from requested_spec on
            // purpose: the material lost cutting a round shape out of a cube is
            // recovered by billing the whole template volume (CUT-06).
            $table->decimal('template_length_m', 10, 4)->nullable();
            $table->decimal('template_width_m', 10, 4)->nullable();
            $table->decimal('template_height_m', 10, 4)->nullable();
            $table->decimal('template_volume_m3', 12, 6)->nullable();

            // Where the cut pieces land once produced.
            $table->uuid('output_inventory_item_id')->nullable();
            $table->foreign('output_inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('foam_block_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cutter_work_order_line_id')->constrained('cutter_work_order_lines')->cascadeOnDelete();
            $table->foreignUuid('stock_lot_id')->constrained('stock_lots')->cascadeOnDelete();

            $table->decimal('block_volume_m3', 12, 6);
            $table->decimal('volume_consumed_m3', 12, 6);

            // full when the template uses essentially the whole block, partial
            // when usable material remains — which v1 turns into byproduct.
            $table->string('consumption_type', 20);

            // Split of the block's cost: the template's share versus what the
            // offcut carries. They always sum to the block's full cost, so no
            // material value is left unaccounted.
            $table->decimal('consumed_cost', 15, 4)->default(0);
            $table->decimal('remainder_cost', 15, 4)->default(0);

            $table->timestamps();

            // A block is a single physical object; it cannot be cut twice.
            $table->unique('stock_lot_id');
        });

        Schema::create('byproduct_yields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cutter_work_order_id')->constrained('cutter_work_orders')->cascadeOnDelete();
            $table->uuid('stock_lot_id')->nullable();
            $table->foreign('stock_lot_id')->references('id')->on('stock_lots')->nullOnDelete();

            // CUT-04: recorded even when nothing was salvaged. A zero is a
            // measurement; a missing row is an unanswered question.
            $table->decimal('weight_kg', 12, 4);
            $table->decimal('yield_cost', 15, 4)->default(0);

            $table->foreignUuid('weighed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weighed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('byproduct_yields');
        Schema::dropIfExists('foam_block_consumptions');
        Schema::dropIfExists('cutter_work_order_lines');
        Schema::dropIfExists('cutter_work_orders');
    }
};
