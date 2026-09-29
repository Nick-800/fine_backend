<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A cutter order may cut several foam blocks (an Arabic sofa's pieces
     * rarely fit one). The single block on the order header moves into this
     * table, snapshot and all, and the header columns go.
     */
    public function up(): void
    {
        Schema::create('cutter_work_order_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cutter_work_order_id')->constrained('cutter_work_orders')->cascadeOnDelete();
            // A block is reserved for one order at a time.
            $table->foreignUuid('stock_lot_id')->unique()->constrained('stock_lots')->restrictOnDelete();

            // Locked in at attachment — the block's cost and size as sold.
            $table->decimal('unit_cost_snapshot', 15, 4);
            $table->decimal('length_m_snapshot', 8, 4)->nullable();
            $table->decimal('width_m_snapshot', 8, 4)->nullable();
            $table->decimal('height_m_snapshot', 8, 4)->nullable();
            $table->decimal('volume_m3_snapshot', 12, 6)->nullable();
            $table->timestamps();
        });

        DB::table('cutter_work_orders')->whereNotNull('stock_lot_id')->orderBy('created_at')->get()
            ->each(function (object $order): void {
                // Cut or still reserved, the block stays the order's record
                // of what it used — its lot status says which.
                DB::table('cutter_work_order_blocks')->insert([
                    'id' => (string) Str::uuid(),
                    'cutter_work_order_id' => $order->id,
                    'stock_lot_id' => $order->stock_lot_id,
                    'unit_cost_snapshot' => $order->block_unit_cost_snapshot ?? 0,
                    'length_m_snapshot' => $order->block_length_m_snapshot,
                    'width_m_snapshot' => $order->block_width_m_snapshot,
                    'height_m_snapshot' => $order->block_height_m_snapshot,
                    'volume_m3_snapshot' => $order->block_volume_m3_snapshot,
                    'created_at' => $order->updated_at,
                    'updated_at' => $order->updated_at,
                ]);
            });

        Schema::table('cutter_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_lot_id');
            $table->dropColumn([
                'block_unit_cost_snapshot',
                'block_length_m_snapshot',
                'block_width_m_snapshot',
                'block_height_m_snapshot',
                'block_volume_m3_snapshot',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('cutter_work_orders', function (Blueprint $table): void {
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->decimal('block_unit_cost_snapshot', 15, 4)->nullable();
            $table->decimal('block_length_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_width_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_height_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_volume_m3_snapshot', 12, 6)->nullable();
        });

        Schema::dropIfExists('cutter_work_order_blocks');
    }
};
