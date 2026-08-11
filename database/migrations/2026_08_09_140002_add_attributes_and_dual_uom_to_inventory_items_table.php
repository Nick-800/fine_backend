<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->uuid('category_id')->nullable()->after('id')->index();
            $table->foreign('category_id')->references('id')->on('item_categories')->nullOnDelete();
            $table->string('primary_uom')->nullable()->after('unit_of_measure'); // Container: barrel, block, pallet
            $table->string('secondary_uom')->nullable()->after('primary_uom'); // Measure: liter, m3, kg
            $table->json('default_attributes')->nullable()->after('secondary_uom');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'primary_uom', 'secondary_uom', 'default_attributes', 'deleted_at']);
        });
    }
};
