<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();

            // The finished-good inventory item this product becomes when built.
            // Required because a completed order has to land somewhere in stock.
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();

            // sale_price = (material + labor) × markup_factor for the preview.
            $table->decimal('markup_factor', 6, 3)->default(1.200);

            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('boms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();

            $table->unsignedInteger('version');

            // One active BOM per product at a time (FUR-01); enforced in the
            // service when activating, with this pair unique as the backstop.
            $table->boolean('is_active')->default(false);

            // Custom orders clone an existing BOM and adapt the copy (FUR-03).
            // The trail back to the original explains where a one-off came from.
            $table->uuid('cloned_from_bom_id')->nullable();
            $table->foreign('cloned_from_bom_id')->references('id')->on('boms')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'version']);
        });

        Schema::create('bom_component_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();

            // Any inventory item can be a component — cut pieces, slices,
            // fabric, wood, springs (FUR-02). No type restriction.
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->decimal('quantity', 12, 4);

            // For the price preview only. Actual costing uses the real lot costs
            // at consumption time, so an estimate drifting stale cannot corrupt
            // the ledger — only the quote.
            $table->decimal('estimated_unit_cost', 15, 4)->default(0);

            $table->timestamps();
        });

        Schema::create('labor_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();

            $table->string('role', 50); // tailor | carpenter | upholsterer | assembler | operator | other
            $table->decimal('estimated_hours', 8, 2);

            // Rate lives here until Phase 09 introduces proper labor role rates;
            // logs snapshot from it (FUR-06) so later rate edits do not rewrite
            // history.
            $table->decimal('hourly_rate', 15, 4)->default(0);

            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();

            // Null client = a stock order rather than a client order.
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();

            $table->string('order_number')->unique();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 50)->default('requested');

            // Actuals, filled by consumption and labor logs — never estimates.
            $table->decimal('material_cost', 15, 4)->default(0);
            $table->decimal('labor_cost', 15, 4)->default(0);

            $table->uuid('finished_stock_lot_id')->nullable();
            $table->foreign('finished_stock_lot_id')->references('id')->on('stock_lots')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
        });

        Schema::create('labor_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('role', 50);
            $table->decimal('hours_logged', 8, 2);

            // Snapshot at log time (FUR-06): later rate changes must not move
            // the cost of work already done.
            $table->decimal('hourly_rate_at_log', 15, 4);

            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index('production_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_logs');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('labor_requirements');
        Schema::dropIfExists('bom_component_lines');
        Schema::dropIfExists('boms');
        Schema::dropIfExists('products');
    }
};
