<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutter_work_orders', function (Blueprint $table) {
            $table->foreignUuid('stock_lot_id')
                ->nullable()
                ->after('notes')
                ->constrained('stock_lots')
                ->restrictOnDelete();

            $table->decimal('block_unit_cost_snapshot', 15, 4)->nullable()->after('stock_lot_id');
            $table->decimal('block_length_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_width_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_height_m_snapshot', 8, 4)->nullable();
            $table->decimal('block_volume_m3_snapshot', 12, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cutter_work_orders', function (Blueprint $table) {
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
};
