<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Nullable: a shared/company-wide bundle every unit can sell,
            // or set to a specific unit for a unit-only promo — same
            // OperatingUnitOrSharedScope pattern as item_categories.
            $table->uuid('operating_unit_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            // A pre-fill hint only, never enforced — quantity is decided at
            // the moment of selling, not in the bundle definition.
            $table->decimal('suggested_quantity', 12, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
    }
};
