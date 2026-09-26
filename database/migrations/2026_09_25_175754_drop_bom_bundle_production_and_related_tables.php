<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the Furniture BOM/production-order module, the Foam tank/
     * chemical-consumption module, inventory attribute definitions, and
     * stock adjustment requests — superseded by the Bundle redesign.
     * Dropped children-before-parents so FK constraints never block a step.
     */
    public function up(): void
    {
        Schema::dropIfExists('consumption_lines');
        Schema::dropIfExists('consumption_reports');
        Schema::dropIfExists('labor_logs');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_component_lines');
        Schema::dropIfExists('labor_requirements');
        Schema::dropIfExists('boms');
        Schema::dropIfExists('inventory_item_attribute_definitions');
        Schema::dropIfExists('inventory_attribute_definitions');
        Schema::dropIfExists('stock_adjustment_requests');
        Schema::dropIfExists('tank_stocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('tank_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chemical_inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->decimal('quantity_on_hand', 15, 4)->default(0.0000);
            $table->decimal('weighted_avg_unit_cost', 15, 4)->default(0.0000);
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->unique(['chemical_inventory_item_id', 'operating_unit_id'], 'tank_item_unit_unique');
        });

        Schema::create('stock_adjustment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->foreignUuid('stock_lot_id')->constrained('stock_lots')->onDelete('cascade');
            $table->string('reason_code', 50);
            $table->decimal('quantity_delta', 15, 4);
            $table->text('notes')->nullable();
            $table->string('status', 50)->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
            $table->index('status');
        });

        Schema::create('inventory_attribute_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('category_id')->nullable()->index();
            $table->foreign('category_id')->references('id')->on('item_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('data_type')->default('text');
            $table->string('unit_of_measure')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required_on_lot')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_item_attribute_definitions', function (Blueprint $table): void {
            $table->uuid('inventory_item_id');
            $table->uuid('attribute_definition_id');

            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('inventory_items')
                ->cascadeOnDelete();

            $table->foreign('attribute_definition_id')
                ->references('id')
                ->on('inventory_attribute_definitions')
                ->cascadeOnDelete();

            $table->primary(['inventory_item_id', 'attribute_definition_id'], 'item_attr_primary');
        });

        Schema::create('boms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);
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
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('estimated_unit_cost', 15, 4)->default(0);
            $table->decimal('target_length_m', 8, 4)->nullable();
            $table->decimal('target_width_m', 8, 4)->nullable();
            $table->decimal('target_height_m', 8, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('labor_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->string('role', 50);
            $table->decimal('estimated_hours', 8, 2);
            $table->decimal('hourly_rate', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 50)->default('requested');
            $table->decimal('material_cost', 15, 4)->default(0);
            $table->decimal('labor_cost', 15, 4)->default(0);
            $table->uuid('finished_stock_lot_id')->nullable();
            $table->foreign('finished_stock_lot_id')->references('id')->on('stock_lots')->nullOnDelete();
            $table->unsignedInteger('awaiting_material_requests_count')->default(0);
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
            $table->decimal('hourly_rate_at_log', 15, 4);
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index('production_order_id');
        });

        Schema::create('consumption_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_batch_id')->constrained('production_batches')->cascadeOnDelete();
            $table->timestamp('reported_at');
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->unique('production_batch_id');
        });

        Schema::create('consumption_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('consumption_report_id')->constrained('consumption_reports')->cascadeOnDelete();
            $table->foreignUuid('tank_stock_id')->nullable()->constrained('tank_stocks')->nullOnDelete();
            $table->foreignUuid('chemical_inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity_consumed', 15, 4);
            $table->decimal('unit_cost_at_consumption', 15, 4);
            $table->timestamps();

            $table->index('chemical_inventory_item_id');
        });
    }
};
