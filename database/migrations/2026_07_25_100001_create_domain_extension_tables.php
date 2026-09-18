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
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->unique()->constrained('entities')->onDelete('cascade');
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('restrict');
            $table->string('job_title', 150);
            $table->string('pay_type', 50)->default('monthly'); // hourly | monthly | piece_rate
            $table->date('hire_date');
            $table->string('status', 50)->default('active'); // active | terminated | on_leave
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('operating_unit_id');
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->unique()->constrained('entities')->onDelete('cascade');
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->onDelete('restrict');
            $table->decimal('credit_limit', 15, 4)->default(0.0000);
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->foreignUuid('account_id')->nullable()->constrained('accounts')->onDelete('restrict');
            $table->string('status', 50)->default('active'); // active | suspended | blacklisted
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index('operating_unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('employees');
    }
};
