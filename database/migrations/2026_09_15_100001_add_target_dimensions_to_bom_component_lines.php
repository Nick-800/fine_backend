<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_component_lines', function (Blueprint $table) {
            // When set, the resolver matches stock_lots by EXACT dimensions
            // (length_m, width_m, height_m). Lines without these stay generic
            // and resolve as ordinary stock reservations.
            $table->decimal('target_length_m', 8, 4)->nullable()->after('estimated_unit_cost');
            $table->decimal('target_width_m', 8, 4)->nullable();
            $table->decimal('target_height_m', 8, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bom_component_lines', function (Blueprint $table) {
            $table->dropColumn(['target_length_m', 'target_width_m', 'target_height_m']);
        });
    }
};
