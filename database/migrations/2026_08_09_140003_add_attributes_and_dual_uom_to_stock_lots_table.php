<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->decimal('container_quantity', 15, 4)->default(1.0000)->after('quantity');
            $table->json('attribute_values')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn(['container_quantity', 'attribute_values']);
        });
    }
};
