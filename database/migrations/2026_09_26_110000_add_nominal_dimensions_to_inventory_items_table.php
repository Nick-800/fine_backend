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
            // Spec/catalog size for this SKU — the intended dimensions, not a
            // measured one. Compared against stock_lots' own length_m/width_m/
            // height_m (the actual measured size of a physical piece) to check
            // spec-vs-actual variance.
            $table->decimal('nominal_length_m', 8, 3)->nullable()->after('default_attributes');
            $table->decimal('nominal_width_m', 8, 3)->nullable()->after('nominal_length_m');
            $table->decimal('nominal_height_m', 8, 3)->nullable()->after('nominal_width_m');
            $table->decimal('nominal_volume_m3', 10, 4)->nullable()->after('nominal_height_m');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['nominal_length_m', 'nominal_width_m', 'nominal_height_m', 'nominal_volume_m3']);
        });
    }
};
