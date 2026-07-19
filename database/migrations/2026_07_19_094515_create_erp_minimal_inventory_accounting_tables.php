<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('item_type', 50); // raw_material, foam_block, cut_template_piece, slice, byproduct_fill, furniture_finished_good, packaging, barrel, pallet
            $table->string('unit_of_measure', 50); // each, m3, kg, meter, liter
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chart_of_accounts_id')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->string('account_code')->unique();
            $table->string('name');
            $table->string('type', 50); // asset, liability, equity, revenue, expense
            $table->string('currency', 3)->default('LYD');
            $table->uuid('parent_account_id')->nullable();
            $table->foreign('parent_account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('inventory_items');
    }
};
