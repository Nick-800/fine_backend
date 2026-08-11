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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tank_stocks');
    }
};
