<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable `account_id` to `fixed_assets` so an admin can attach the
     * asset to a per-asset sub-account on the chart of accounts (preferred
     * parent: 14 — الأصول الثابتة). When the column is null we keep using the
     * universal "14" account on the acquisition journal line — same
     * behaviour as before this migration.
     *
     * Mirrors `2026_09_26_102648_add_account_id_to_suppliers_table.php`.
     */
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->foreignUuid('account_id')
                ->nullable()
                ->after('company_id')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
