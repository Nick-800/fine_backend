<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->decimal('held_amount_lyd', 15, 4);
            $table->decimal('exact_amount_used', 15, 4)->default(0);
            $table->decimal('released_amount', 15, 4)->default(0);
            $table->string('bank_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_holds');
    }
};
