<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->onDelete('set null');
            $table->foreignUuid('from_warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->foreignUuid('to_warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->string('sku');
            $table->string('movement_type', 50)->default('adjustment'); // receipt, issue, transfer, adjustment, consumption, production_output, byproduct_yield, sale
            $table->decimal('quantity_delta', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->string('reason')->default('inventory_movement');
            $table->string('reference_document_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
            $table->index('sku');
            $table->index('reference_id');
            $table->index(['reference_document_type', 'reference_id'], 'inv_mov_ref_doc_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
