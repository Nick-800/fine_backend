<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_attribute_definitions');
    }
};
