<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overhead_allocations', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('absorbed');
            $table->foreignUuid('approved_by_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->foreignUuid('paid_by_user_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by_user_id');
            $table->text('confirmation_note')->nullable()->after('paid_at');
            $table->index('status');
        });

        Schema::table('landed_cost_lines', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('is_confirmed');
            $table->foreignUuid('approved_by_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->foreignUuid('paid_by_user_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by_user_id');
            $table->text('confirmation_note')->nullable()->after('paid_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('overhead_allocations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn(['status', 'approved_at', 'paid_at', 'confirmation_note']);
        });

        Schema::table('landed_cost_lines', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn(['status', 'approved_at', 'paid_at', 'confirmation_note']);
        });
    }
};
