<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Maps every accounting event to one chart-of-accounts sub-account at the
     * operating-unit level. One row per (operating_unit, event) — replaces
     * the per-item `inventory_item_accounts` table, because every item is
     * unit-shared and the relevant scope for picking a ledger account is the
     * unit the movement is happening in (intake warehouse / sale order),
     * not the catalog item.
     *
     * Posting services resolve via `OperatingUnit::accountFor()`; an absent
     * row triggers `INVENTORY_ACCOUNT_NOT_LINKED` 422 (no canonical
     * fallback). The seeder pre-fills the five operating units with the
     * standard sub-account codes so a fresh install is ready to move
     * stock without manual setup.
     */
    public function up(): void
    {
        Schema::create('operating_unit_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['operating_unit_id', 'event_type'], 'operating_unit_accounts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operating_unit_accounts');
    }
};
