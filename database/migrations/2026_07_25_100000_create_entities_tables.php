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
        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('entity_type', 50)->default('individual');
            $table->string('tax_number', 100)->nullable();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('entity_type');
            $table->index('tax_number');
        });

        Schema::create('entity_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->onDelete('cascade');
            $table->string('role_type', 50);
            $table->foreignUuid('operating_unit_id')->nullable()->constrained('operating_units')->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['entity_id', 'role_type', 'operating_unit_id'], 'uq_entity_role_unit');
            $table->index(['entity_id', 'role_type']);
        });

        Schema::create('entity_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->onDelete('cascade');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('LY');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index('entity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_contacts');
        Schema::dropIfExists('entity_roles');
        Schema::dropIfExists('entities');
    }
};
