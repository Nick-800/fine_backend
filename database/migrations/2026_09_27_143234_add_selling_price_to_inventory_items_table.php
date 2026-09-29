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
            // The POS starting price — always editable at the counter.
            // Basis 'unit' prices one piece; 'm3' prices a cubic metre, so a
            // sized piece is rate × L × W × H.
            $table->decimal('selling_price', 15, 4)->nullable()->after('volume_m3');
            $table->string('price_basis', 10)->default('unit')->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['selling_price', 'price_basis']);
        });
    }
};
