<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // What the client currently owes. Moves up on fulfillment (AR) and
            // down on payment; the credit check compares against credit_limit.
            $table->decimal('current_balance', 15, 4)->default(0)->after('credit_limit');
        });

        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The selling unit.
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();

            $table->string('order_number')->unique();

            // One model for every kind of sale (SALE-04): an external client, a
            // sibling unit, or an anonymous POS walk-in.
            $table->string('buyer_type', 20); // client | internal_unit | walk_in
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->uuid('buyer_unit_id')->nullable();
            $table->foreign('buyer_unit_id')->references('id')->on('operating_units')->nullOnDelete();

            $table->string('channel', 20)->default('standard'); // standard | pos
            $table->string('status', 50)->default('draft');

            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->string('payment_method', 20)->nullable(); // cash | card

            $table->text('notes')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operating_unit_id', 'status']);
            $table->index('channel');
        });

        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 15, 4);

            // Actual cost of the lots drawn at fulfillment — the COGS side.
            // Zero until fulfilled, because it cannot be known before the lots
            // are picked.
            $table->decimal('unit_cost_actual', 15, 4)->default(0);

            $table->timestamps();
        });

        Schema::create('credit_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();

            $table->decimal('amount_over_limit', 15, 4);
            $table->string('status', 20)->default('pending'); // pending | approved | rejected

            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('internal_restock_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('request_number')->unique();

            // The store asking, and the unit being asked.
            $table->foreignUuid('requesting_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignUuid('source_unit_id')->constrained('operating_units')->cascadeOnDelete();

            $table->string('status', 30)->default('pending_approval'); // pending_approval | approved | rejected | fulfilled

            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->index(['source_unit_id', 'status']);
        });

        Schema::create('internal_restock_request_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('internal_restock_request_id')
                ->constrained('internal_restock_requests')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->decimal('quantity', 12, 4);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_restock_request_lines');
        Schema::dropIfExists('internal_restock_requests');
        Schema::dropIfExists('credit_approval_requests');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('current_balance');
        });
    }
};
