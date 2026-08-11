<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            // Ordinal position within the run. Null for non-foam lots.
            $table->unsignedInteger('sequence_in_batch')->nullable()->after('lot_number');

            // Measured at grading and entered manually. Part of the block code,
            // and a primary cutter-selection filter — hence a column, not a JSON attribute.
            $table->unsignedInteger('pressure')->nullable()->after('sequence_in_batch');

            // Parentage of a cut remnant, recorded as data rather than encoded in lot_number.
            $table->uuid('remnant_of_lot_id')->nullable()->after('production_batch_id');

            $table->foreign('production_batch_id')->references('id')->on('production_batches')->nullOnDelete();
            $table->foreign('remnant_of_lot_id')->references('id')->on('stock_lots')->nullOnDelete();

            // Backstop for the sequence reservation in ProductionBatchService.
            $table->unique(['production_batch_id', 'sequence_in_batch'], 'lot_batch_sequence_unique');
            $table->index('pressure');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropForeign(['production_batch_id']);
            $table->dropForeign(['remnant_of_lot_id']);
            $table->dropUnique('lot_batch_sequence_unique');
            $table->dropIndex(['pressure']);
            $table->dropColumn(['sequence_in_batch', 'pressure', 'remnant_of_lot_id']);
        });
    }
};
