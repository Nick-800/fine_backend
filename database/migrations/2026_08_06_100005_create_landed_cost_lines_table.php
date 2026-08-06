<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_order_id')->constrained('import_orders')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('LYD');
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_lines');
    }
};
