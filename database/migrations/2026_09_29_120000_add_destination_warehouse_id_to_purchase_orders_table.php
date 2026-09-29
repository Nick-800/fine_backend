<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the purchased goods will end up as StockLots once the order
     * reaches its final accounting step (foreign `complete`, local `paid`).
     *
     * The foreign flow already has `arrived_warehouse_id` (set during the
     * logistics `arriveAtWarehouse` transition) and uses it as an override;
     * local flow has no such override. Both flows need this column so the
     * operator can declare the destination up-front, on create.
     *
     * Restrict on delete: deleting a warehouse that an order points at
     * would orphan the purchase history.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignUuid('destination_warehouse_id')
                ->nullable()
                ->after('arrived_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_warehouse_id');
        });
    }
};
