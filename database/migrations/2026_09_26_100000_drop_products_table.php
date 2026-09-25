<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The products table was the pricing/BOM wrapper around a finished-good
     * inventory item. Its only reason to exist — boms/production_orders —
     * was already dropped in 2026_09_25_175754; nothing references
     * products.id in the live schema anymore, so it's dead weight.
     */
    public function up(): void
    {
        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->decimal('markup_factor', 6, 3)->default(1.200);
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
