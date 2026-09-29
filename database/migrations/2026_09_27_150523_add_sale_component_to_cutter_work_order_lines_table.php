<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutter_work_order_lines', function (Blueprint $table): void {
            // The sold bundle piece this line cuts. Null = a line the cutter
            // added itself (for stock, a phone order…). One order may carry
            // pieces from several sales and such extra lines together.
            $table->foreignUuid('sale_bundle_component_id')->nullable()->after('output_inventory_item_id')
                ->constrained('sale_bundle_components')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cutter_work_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sale_bundle_component_id');
        });
    }
};
