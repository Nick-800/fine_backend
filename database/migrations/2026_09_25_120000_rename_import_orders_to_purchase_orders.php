<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wave 5 (Purchase-orders branch): rename `purchase_orders` →
     * `purchase_orders` everywhere, add a `kind` discriminator column
     * (`'foreign'` | `'local'`), add per-line `received_quantity` for
     * partial receipts, and migrate the polymorphic journal entry
     * `source_document_type = 'PurchaseOrder'` string to `'PurchaseOrder'`.
     *
     * The `kind` column is backfilled from the existing `currency`
     * field: `currency = 'LYD'` ⇒ `local`, else stays `foreign`.
     *
     * All FK columns referencing `purchase_order_id` are renamed to
     * `purchase_order_id` so the foreign key stays valid.
     *
     * The existing `PurchaseOrderStatus` enum is renamed to
     * `PurchaseOrderStatus` and gains two cases (`approved`, `closed`)
     * used by the local purchase-order flow.
     */
    public function up(): void
    {
        // 1. Rename tables. SQLite supports `ALTER TABLE old RENAME TO new`.
        //    Idempotency: skip if the source table no longer exists.
        if (Schema::hasTable('import_orders') && ! Schema::hasTable('purchase_orders')) {
            Schema::rename('import_orders', 'purchase_orders');
        }
        if (Schema::hasTable('import_order_items') && ! Schema::hasTable('purchase_order_items')) {
            Schema::rename('import_order_items', 'purchase_order_items');
        }

        // 2. Rename FK columns in dependent tables. SQLite supports
        //    `ALTER TABLE ... RENAME COLUMN` since 3.25. Idempotent.
        if (Schema::hasColumn('purchase_order_items', 'import_order_id')) {
            Schema::table('purchase_order_items', function (Blueprint $t) {
                $t->renameColumn('import_order_id', 'purchase_order_id');
            });
        }
        if (Schema::hasColumn('landed_cost_lines', 'import_order_id')) {
            Schema::table('landed_cost_lines', function (Blueprint $t) {
                $t->renameColumn('import_order_id', 'purchase_order_id');
            });
        }
        if (Schema::hasColumn('payment_requests', 'import_order_id')) {
            Schema::table('payment_requests', function (Blueprint $t) {
                $t->renameColumn('import_order_id', 'purchase_order_id');
            });
        }
        if (Schema::hasColumn('goods_receipts', 'import_order_id')) {
            Schema::table('goods_receipts', function (Blueprint $t) {
                $t->renameColumn('import_order_id', 'purchase_order_id');
            });
        }

        // 3. Add the `kind` discriminator column. NOT NULL with a default
        //    is supported because existing rows fill in the default on
        //    the column add. Idempotent: skip if already present.
        if (! Schema::hasColumn('purchase_orders', 'kind')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->string('kind', 16)->default('foreign')->after('currency');
            });
        }

        // 4. Backfill `kind` from `currency`: LYD rows are local.
        DB::statement("UPDATE purchase_orders SET kind = 'local' WHERE currency = 'LYD' AND kind IS NULL");

        // 5. Per-line-item receipt tracking for partial receipts.
        if (! Schema::hasColumn('purchase_order_items', 'received_quantity')) {
            Schema::table('purchase_order_items', function (Blueprint $t) {
                $t->decimal('received_quantity', 15, 4)->nullable()->after('quantity');
            });
        }

        // 6. Migrate the polymorphic journal entry discriminator.
        //    `source_document_type` is a nullable string (not an enum)
        //    so the UPDATE is straightforward. Idempotent: only updates rows
        //    that still carry the old string.
        DB::statement("UPDATE journal_entries SET source_document_type = 'PurchaseOrder' WHERE source_document_type = 'ImportOrder'");
    }

    public function down(): void
    {
        // Reverse the journal entry discriminator.
        DB::statement("UPDATE journal_entries SET source_document_type = 'PurchaseOrder' WHERE source_document_type = 'PurchaseOrder'");

        // Drop the new columns.
        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->dropColumn('received_quantity');
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropColumn('kind');
        });

        // Reverse the FK column renames.
        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->renameColumn('purchase_order_id', 'purchase_order_id');
        });
        Schema::table('payment_requests', function (Blueprint $t) {
            $t->renameColumn('purchase_order_id', 'purchase_order_id');
        });
        Schema::table('landed_cost_lines', function (Blueprint $t) {
            $t->renameColumn('purchase_order_id', 'purchase_order_id');
        });
        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->renameColumn('purchase_order_id', 'purchase_order_id');
        });

        // Reverse the table renames.
        Schema::rename('purchase_order_items', 'purchase_order_items');
        Schema::rename('purchase_orders', 'purchase_orders');
    }
};
