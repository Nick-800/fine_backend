<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The downstream module that owns this request.
            $table->string('fulfilling_module', 30);

            // What is needed.
            $table->foreignUuid('inventory_item_id')
                ->constrained('inventory_items')
                ->restrictOnDelete();
            $table->decimal('quantity', 15, 4);

            // Optional specs for dimensional items (foam blocks: length/width/height).
            $table->json('target_dimensions')->nullable();

            // Status lifecycle: pending → in_progress → fulfilled | cancelled.
            $table->string('status', 30)->default('pending');

            // Chain: a cutter MR that escalates to a foam MR links via this FK.
            $table->foreignUuid('parent_request_id')->nullable()
                ->constrained('material_requests')
                ->nullOnDelete();

            // Polymorphic "what is this for". Today: production_order.
            // Future: cutter_work_order (escalations from the cutter itself).
            $table->string('requested_for_type', 30)->nullable();
            $table->uuid('requested_for_id')->nullable();

            // Filled when status = fulfilled. Names the model that satisfied
            // the request (cutter_work_order, production_batch, ...).
            $table->string('fulfilled_by_type', 30)->nullable();
            $table->uuid('fulfilled_by_id')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            $table->foreignUuid('operating_unit_id')
                ->constrained('operating_units')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->index(['status', 'fulfilling_module']);
            $table->index(['requested_for_type', 'requested_for_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
