<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_attribute_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('category_id')->nullable()->index();
            $table->foreign('category_id')->references('id')->on('item_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('data_type')->default('text'); // number, text, select, boolean
            $table->string('unit_of_measure')->nullable(); // kPa, kg/m3, L, %
            $table->json('options')->nullable(); // for select types
            $table->boolean('is_required_on_lot')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_attribute_definitions');
    }
};
