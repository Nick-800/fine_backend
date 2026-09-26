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
            // This SKU's own length/width/height — same column names as
            // stock_lots, which carries them per physical lot instead of
            // per catalog item.
            $table->decimal('length_m', 8, 3)->nullable()->after('default_attributes');
            $table->decimal('width_m', 8, 3)->nullable()->after('length_m');
            $table->decimal('height_m', 8, 3)->nullable()->after('width_m');
            $table->decimal('volume_m3', 10, 4)->nullable()->after('height_m');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['length_m', 'width_m', 'height_m', 'volume_m3']);
        });
    }
};
