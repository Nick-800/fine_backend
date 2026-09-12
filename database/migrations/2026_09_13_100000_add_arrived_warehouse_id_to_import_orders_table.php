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
            $table->foreignUuid('arrived_warehouse_id')
                ->nullable()
                ->after('booked_fx_rate')
                ->constrained('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arrived_warehouse_id');
        });
    }
};
