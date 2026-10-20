<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account used to cascade into its journal lines, silently
 * rewriting posted history (BR-GL-004). An account with lines is now refused
 * by the controller, and the database refuses it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }
};
