<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreignUuid('source_stock_lot_id')
                ->nullable()
                ->after('remnant_of_lot_id')
                ->constrained('stock_lots')
                ->nullOnDelete();

            $table->decimal('source_block_length_m', 8, 4)->nullable();
            $table->decimal('source_block_width_m', 8, 4)->nullable();
            $table->decimal('source_block_height_m', 8, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_stock_lot_id');
            $table->dropColumn([
                'source_block_length_m',
                'source_block_width_m',
                'source_block_height_m',
            ]);
        });
    }
};
