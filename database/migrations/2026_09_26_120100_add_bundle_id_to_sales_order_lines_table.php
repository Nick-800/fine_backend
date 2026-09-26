<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            // nullOnDelete: a bundle template can be deleted later without
            // breaking historical sold lines.
            $table->foreignUuid('bundle_id')
                ->nullable()
                ->after('stock_lot_id')
                ->constrained('bundles')
                ->nullOnDelete();
            $table->index('bundle_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropIndex(['bundle_id']);
            $table->dropConstrainedForeignId('bundle_id');
        });
    }
};
