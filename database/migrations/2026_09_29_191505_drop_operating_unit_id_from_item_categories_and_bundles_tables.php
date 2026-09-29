<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item categories and bundles are catalog data shared by every operating
     * unit. Existing rows simply become global.
     */
    public function up(): void
    {
        foreach (['item_categories', 'bundles'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'operating_unit_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['operating_unit_id']);
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('operating_unit_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['item_categories', 'bundles'] as $tableName) {
            if (Schema::hasColumn($tableName, 'operating_unit_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('operating_unit_id')->nullable()->index();
            });
        }
    }
};
