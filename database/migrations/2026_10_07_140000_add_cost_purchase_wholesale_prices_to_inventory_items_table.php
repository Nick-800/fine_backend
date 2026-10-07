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
            $table->decimal('cost_price', 15, 4)->nullable()->after('volume_m3');
            $table->decimal('purchase_price', 15, 4)->nullable()->after('cost_price');
            $table->decimal('wholesale_price', 15, 4)->nullable()->after('price_basis');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['cost_price', 'purchase_price', 'wholesale_price']);
        });
    }
};
