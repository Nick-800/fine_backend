<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // Null unit = a company-level asset (HQ building, shared vehicle).
            $table->foreignUuid('operating_unit_id')->nullable()->constrained('operating_units')->nullOnDelete();
            $table->string('name');
            $table->string('asset_code', 50)->unique();
            $table->decimal('acquisition_cost', 15, 4);
            $table->date('acquisition_date');
            $table->string('depreciation_method', 30);
            $table->unsignedInteger('useful_life_years');
            $table->decimal('salvage_value', 15, 4)->default(0);
            // Denormalised running total; depreciation_entries are the audit trail.
            $table->decimal('accumulated_depreciation', 15, 4)->default(0);
            $table->string('status', 30)->default('active');
            $table->decimal('disposal_proceeds', 15, 4)->nullable();
            $table->date('disposed_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            // One posting per asset per month (ACC-07: idempotent re-runs).
            $table->string('period', 7);
            $table->decimal('amount', 15, 4);
            $table->decimal('book_value_after', 15, 4);
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
        Schema::dropIfExists('fixed_assets');
    }
};
