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
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->string('lot_number')->unique();
            $table->decimal('quantity', 15, 4)->default(1.0000);
            $table->decimal('length_m', 8, 3)->nullable();
            $table->decimal('width_m', 8, 3)->nullable();
            $table->decimal('height_m', 8, 3)->nullable();
            $table->decimal('volume_m3', 10, 4)->nullable();
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0.0000);
            $table->string('grade', 50)->default('standard'); // standard, acceptable_variant, defective_usable, reject
            $table->string('status', 50)->default('available'); // available, reserved, consumed, quarantined
            $table->uuid('production_batch_id')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('inventory_item_id');
            $table->index('warehouse_id');
            $table->index(['status', 'grade']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
