<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_order_id')->constrained('import_orders')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->string('currency', 3);
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('import_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_order_items');
    }
};
