<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Counter maintained by MaterialResolutionService. The order can't
            // transition past bom_confirmed until this is back to zero.
            $table->unsignedInteger('awaiting_material_requests_count')
                ->default(0)
                ->after('finished_stock_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('awaiting_material_requests_count');
        });
    }
};
