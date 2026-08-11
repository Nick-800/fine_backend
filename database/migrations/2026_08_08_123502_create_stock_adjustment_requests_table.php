<?php

declare(strict_types=1);

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
        Schema::create('stock_adjustment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('cascade');
            $table->foreignUuid('stock_lot_id')->constrained('stock_lots')->onDelete('cascade');
            $table->string('reason_code', 50); // audit_reconciliation, spill_loss, damage, expired
            $table->decimal('quantity_delta', 15, 4);
            $table->text('notes')->nullable();
            $table->string('status', 50)->default('pending'); // pending, approved, rejected
            $table->foreignUuid('requested_by_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_requests');
    }
};
