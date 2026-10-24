<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->json('payload');
            $table->decimal('total', 18, 4)->default(0);
            $table->unsignedSmallInteger('line_count')->default(0);
            $table->timestamps();

            $table->index(['operating_unit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
    }
};
