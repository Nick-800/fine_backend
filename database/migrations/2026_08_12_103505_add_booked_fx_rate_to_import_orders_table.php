<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_orders', function (Blueprint $table) {
            // Snapshot of the FX rate at order creation (PROC-06 "booked
            // estimate"). Completion posts FX gain/loss against this; null on
            // legacy orders falls back to rate history, then to the realized
            // rate (no FX difference).
            $table->decimal('booked_fx_rate', 15, 6)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('import_orders', function (Blueprint $table) {
            $table->dropColumn('booked_fx_rate');
        });
    }
};
