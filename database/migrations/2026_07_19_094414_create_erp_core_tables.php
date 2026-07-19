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
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('default_currency', 3)->default('LYD');
            $table->boolean('overhead_absorption_enabled')->default(false);
            $table->string('transfer_pricing_mode', 50)->default('at_cost');
            $table->string('timezone', 100)->default('Africa/Tripoli');
            $table->timestamps();
        });

        Schema::create('unit_blueprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('workflow_set');
            $table->json('default_role_template');
            $table->json('default_inventory_config');
            $table->timestamps();
        });

        Schema::create('operating_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignUuid('blueprint_id')->constrained('unit_blueprints')->onDelete('restrict');
            $table->string('name');
            $table->string('unit_type', 50); // manufactory | store | office
            $table->string('currency', 3)->default('LYD');
            $table->string('status', 50)->default('provisioning'); // provisioning | active | inactive
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_internal_unit')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('operating_units');
        Schema::dropIfExists('unit_blueprints');
        Schema::dropIfExists('companies');
    }
};
