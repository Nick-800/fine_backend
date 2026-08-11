<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->uuid('requested_by_client_id')->nullable();
            $table->foreign('requested_by_client_id')->references('id')->on('clients')->nullOnDelete();

            // Operator-entered, incremental, never reused. Uniqueness intentionally spans
            // soft-deleted rows: a deleted batch may still have labelled blocks in the yard.
            $table->unsignedBigInteger('operation_number')->unique();

            // Constant for the whole run — a machine setting, not per-block data.
            $table->decimal('bun_width_m', 8, 3);

            // density_band, cure_time_minutes, conveyor_speed, chemical_formula
            $table->json('formula_params')->nullable();

            $table->string('status', 50)->default('planned');
            $table->decimal('material_cost', 15, 4)->default(0);

            // Non-serialised output (فاصل / بداية) recorded so material yield reconciles.
            $table->decimal('scrap_volume_m3', 10, 4)->default(0);

            // Backs collision-safe block numbering; never mass-assignable.
            $table->unsignedInteger('next_sequence')->default(1);

            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
