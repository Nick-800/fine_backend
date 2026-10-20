<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entry types and linked reversals (ACC-13). A posted entry never changes; a
 * correction is one mirror entry pointing at it. The unique index on
 * reversal_of_id is the database's guarantee that an entry is reversed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('entry_type', 20)->default('automatic')->after('is_manual')->index();
            $table->foreignUuid('reversal_of_id')->nullable()->after('entry_type')
                ->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable()->after('reversal_of_id');
        });

        DB::table('journal_entries')->where('is_manual', true)->update(['entry_type' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropIndex(['entry_type']);
            $table->dropColumn(['entry_type', 'reversal_of_id', 'reversal_reason']);
        });
    }
};
