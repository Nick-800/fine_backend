<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Maps each inventory item to one chart-of-accounts sub-account per event
     * (purchases, sales, COGS, returns, waste, discounts, transport-in, sales
     * commission, opening/ending). One row per (item, event) — item-global
     * only, no warehouse dimension per agreed scope.
     *
     * Posting services resolve via `InventoryItem::accountFor()`; an absent
     * override triggers `INVENTORY_ACCOUNT_NOT_LINKED` 422 (no canonical
     * fallback). Mirrors the established `account_id` pattern used for
     * suppliers, clients, fixed assets, and purchase-order payment source.
     */
    public function up(): void
    {
        Schema::create('inventory_item_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['inventory_item_id', 'event_type'], 'inventory_item_accounts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_item_accounts');
    }
};
