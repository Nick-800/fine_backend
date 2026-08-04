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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->string('product_sku');
            $table->decimal('quantity', 15, 4);
            $table->string('status');
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
            $table->index('product_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
