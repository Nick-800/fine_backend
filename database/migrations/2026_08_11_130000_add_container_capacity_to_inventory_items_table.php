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
            // How much secondary_uom one primary_uom holds — 40 for a 40L barrel.
            // Null means the item has no container semantics and all container
            // arithmetic is skipped for it.
            $table->decimal('container_capacity', 15, 4)->nullable()->after('secondary_uom');

            // The item representing this product's *empty* container, credited
            // back to stock when one drains. Null means empties are not recovered.
            $table->uuid('empty_container_item_id')->nullable()->after('container_capacity');
            $table->foreign('empty_container_item_id')
                ->references('id')->on('inventory_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropForeign(['empty_container_item_id']);
            $table->dropColumn(['container_capacity', 'empty_container_item_id']);
        });
    }
};
