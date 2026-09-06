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
            $table->foreignUuid('stock_lot_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('stock_lots')
                ->nullOnDelete();
            $table->index('stock_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropIndex(['stock_lot_id']);
            $table->dropConstrainedForeignId('stock_lot_id');
        });
    }
};
