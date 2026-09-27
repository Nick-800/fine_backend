<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable `payment_source_account_id` to `purchase_orders` so the
     * operator can pick which cash/bank COA sub-account a payment leaves
     * from. Required for both flows at the payment step:
     *
     *   - Foreign `execute_payment` action: replaces the previously
     *     hardcoded `'12' Cash and Bank` credit side.
     *   - Local `payLocal` action: a NEW journal entry (DR supplier advance
     *     / CR chosen source) closes the accounting gap that previously
     *     posted nothing on payment.
     *
     * Strict filter (`account_code LIKE '121%'`) is enforced at the API
     * layer; the column itself stays open to any asset account so admins
     * can recover from misclassification without a schema change.
     *
     * Mirrors `2026_09_26_102648_add_account_id_to_suppliers_table.php` and
     * `2026_09_26_160000_add_account_id_to_fixed_assets_table.php`.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignUuid('payment_source_account_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_source_account_id');
        });
    }
};
