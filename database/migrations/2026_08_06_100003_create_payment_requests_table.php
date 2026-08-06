<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('import_order_id')->constrained('import_orders')->cascadeOnDelete();
            $table->string('route');
            $table->string('invoice_ref')->nullable();
            $table->decimal('amount_requested', 15, 4);
            $table->string('status')->default('pending');
            $table->decimal('fx_rate_used', 15, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
