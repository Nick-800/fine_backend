<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_items', function (Blueprint $table): void {
            // Pre-fill hints for the per-sale bundle definition, never enforced.
            // The same item may appear on several rows with different sizes
            // (an Arabic sofa's seat, back and arm pieces).
            $table->decimal('length_m', 8, 3)->nullable()->after('suggested_quantity');
            $table->decimal('width_m', 8, 3)->nullable()->after('length_m');
            $table->decimal('height_m', 8, 3)->nullable()->after('width_m');
            $table->unsignedSmallInteger('position')->default(0)->after('height_m');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_items', function (Blueprint $table): void {
            $table->dropColumn(['length_m', 'width_m', 'height_m', 'position']);
        });
    }
};
