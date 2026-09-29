<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            // item = one inventory item leaving stock at checkout.
            // bundle = one line carrying the bundle's name; its pieces are
            // defined after checkout and have no inventory_item_id of their own.
            $table->string('line_type', 10)->default('item')->after('sales_order_id');

            // What the invoice prints — the bundle name, or the item name.
            $table->string('description')->nullable()->after('line_type');

            // Cart order, so the invoice prints lines as they were sold.
            $table->unsignedSmallInteger('position')->default(0)->after('description');

            // The size sold, for items priced per m³.
            $table->decimal('length_m', 8, 3)->nullable()->after('quantity');
            $table->decimal('width_m', 8, 3)->nullable()->after('length_m');
            $table->decimal('height_m', 8, 3)->nullable()->after('width_m');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->uuid('inventory_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['line_type', 'description', 'position', 'length_m', 'width_m', 'height_m']);
        });
    }
};
