<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->string('item_type', 50)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });
    }
};
