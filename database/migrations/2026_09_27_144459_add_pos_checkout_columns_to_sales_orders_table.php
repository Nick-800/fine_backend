<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            // The treasury or bank a cash/bank sale was received into.
            // payment_method now carries cash | bank | receivable.
            $table->foreignUuid('cash_account_id')->nullable()->after('payment_method')
                ->constrained('cash_accounts')->nullOnDelete();

            // Goods progress, separate from money progress (status).
            $table->string('fulfillment_status', 30)->nullable()->after('status');

            // Generated once per POS cart: a retried checkout returns the
            // sale it already made instead of selling twice.
            $table->uuid('client_request_id')->nullable()->unique()->after('order_number');

            // The quotation this sale was converted from, if any.
            $table->uuid('quotation_id')->nullable()->after('client_request_id');

            $table->foreignUuid('sold_by_user_id')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();

            $table->index('fulfillment_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex(['fulfillment_status']);
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropConstrainedForeignId('sold_by_user_id');
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['fulfillment_status', 'client_request_id', 'quotation_id']);
        });
    }
};
