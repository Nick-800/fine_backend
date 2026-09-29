<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A priced preview the client takes home and comes back with. No stock
        // or ledger effect — converting it runs a normal POS checkout.
        Schema::create('quotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('quotation_number')->unique();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('valid_until');
            $table->string('status', 20)->default('open'); // open | converted | cancelled (expired is derived)
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->uuid('converted_sales_order_id')->nullable();
            $table->foreign('converted_sales_order_id')->references('id')->on('sales_orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['operating_unit_id', 'status']);
        });

        Schema::create('quotation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('line_type', 10)->default('item');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignUuid('bundle_id')->nullable()->constrained('bundles')->nullOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('length_m', 8, 3)->nullable();
            $table->decimal('width_m', 8, 3)->nullable();
            $table->decimal('height_m', 8, 3)->nullable();
            $table->decimal('unit_price', 15, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
