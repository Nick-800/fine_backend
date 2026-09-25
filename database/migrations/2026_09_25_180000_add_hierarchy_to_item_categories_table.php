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
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('operating_unit_id')
                ->constrained('item_categories')
                ->nullOnDelete();

            $table->string('code_segment')->default('')->after('name');
            $table->unsignedSmallInteger('child_code_length')->default(2)->after('item_type');
            $table->unsignedSmallInteger('product_code_length')->default(3)->after('child_code_length');
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['code_segment', 'child_code_length', 'product_code_length']);
        });
    }
};
