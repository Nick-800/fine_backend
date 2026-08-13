<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overhead_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // Null unit = a company-level expense awaiting allocation.
            $table->foreignUuid('operating_unit_id')->nullable()->constrained('operating_units')->nullOnDelete();
            $table->string('category', 50);
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('LYD');
            $table->date('expense_date');
            $table->string('payment_source', 20)->default('cash');
            $table->string('status', 20)->default('recorded');
            $table->timestamp('allocated_at')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('overhead_allocation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('method', 50);
            // Only manual_percentage carries config: {operating_unit_id: percent}.
            $table->json('percentages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('overhead_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('overhead_expense_id')->constrained('overhead_expenses')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('method', 50);
            $table->decimal('amount', 15, 4);
            // Whether this share was pushed into the unit's WIP (ACC-06).
            $table->boolean('absorbed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overhead_allocations');
        Schema::dropIfExists('overhead_allocation_rules');
        Schema::dropIfExists('overhead_expenses');
    }
};
