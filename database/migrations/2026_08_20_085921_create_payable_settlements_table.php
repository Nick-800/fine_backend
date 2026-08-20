<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // Null = settling the company-level portion of the payable.
            $table->foreignUuid('operating_unit_id')->nullable()->constrained('operating_units')->nullOnDelete();
            // Which liability was paid down: 2100 AP, 2210 payroll
            // deductions, 2300 landed cost clearing.
            $table->string('account_code', 10);
            $table->decimal('amount', 15, 4);
            $table->string('reference')->nullable();
            $table->foreignUuid('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('settled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_settlements');
    }
};
