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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->string('sku');
            $table->decimal('quantity_delta', 15, 4);
            $table->string('reason');
            $table->uuid('reference_id')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
            $table->index('sku');
            $table->index('reference_id');
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
