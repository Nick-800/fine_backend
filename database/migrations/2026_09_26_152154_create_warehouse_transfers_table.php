<?php

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
        Schema::create('warehouse_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('transfer_number')->unique();
            $table->foreignUuid('from_warehouse_id')->constrained('warehouses');
            $table->foreignUuid('to_warehouse_id')->constrained('warehouses');
            $table->string('status')->default('draft');
            $table->text('reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('record_version')->default(1);
            $table->timestamps();
        });

        Schema::create('warehouse_transfer_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_transfer_id')->constrained('warehouse_transfers')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items');
            $table->decimal('quantity', 12, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_lines');
        Schema::dropIfExists('warehouse_transfers');
    }
};
