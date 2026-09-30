<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-shot data move + table drop. Every `inventory_item_accounts` row
     * becomes a row in `operating_unit_accounts` against the foam operating
     * unit, because items are first provisioned in the foam warehouse and
     * every seeded row traces back to that unit. Owner is expected to
     * manually verify the other four units' mappings after this migration
     * runs in production.
     *
     * If multiple distinct accounts were linked for the same (event) across
     * items, the upsert picks the first one (unique index on (unit, event)
     * forces this); the rest are discarded and a comment is added to the
     * run output. In practice every per-event mapping is the same chart
     * sub-account, so collisions are rare.
     */
    public function up(): void
    {
        if (! Schema::hasTable('inventory_item_accounts')) {
            return;
        }

        $foamUnit = DB::table('operating_units')->where('name', 'مصنع الإسفنج')->first();

        if ($foamUnit === null) {
            // No foam unit means nothing to migrate. Drop and move on.
            Schema::dropIfExists('inventory_item_accounts');

            return;
        }

        $rows = DB::table('inventory_item_accounts')->get();

        foreach ($rows as $row) {
            $exists = DB::table('operating_unit_accounts')
                ->where('operating_unit_id', $foamUnit->id)
                ->where('event_type', $row->event_type)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('operating_unit_accounts')->insert([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $foamUnit->id,
                'event_type' => $row->event_type,
                'account_id' => $row->account_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::dropIfExists('inventory_item_accounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
};
