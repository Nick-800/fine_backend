<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unify the Chart of Accounts across the entire company:
     * Removes per-unit segmentation (accounts.unit_id) and enforces
     * company-wide uniqueness on account_code.
     */
    public function up(): void
    {
        // 1. Resolve any duplicate account_codes before applying unique constraint.
        if (Schema::hasColumn('accounts', 'unit_id')) {
            $duplicates = DB::table('accounts')
                ->select('account_code', 'chart_of_accounts_id', DB::raw('count(*) as count'))
                ->groupBy('account_code', 'chart_of_accounts_id')
                ->having('count', '>', 1)
                ->get();

            foreach ($duplicates as $dup) {
                $accounts = DB::table('accounts')
                    ->where('account_code', $dup->account_code)
                    ->where('chart_of_accounts_id', $dup->chart_of_accounts_id)
                    ->orderBy('created_at')
                    ->get();

                // Find keeper (prefer one with journal lines)
                $keeper = null;
                foreach ($accounts as $acc) {
                    $hasLines = DB::table('journal_lines')->where('account_id', $acc->id)->exists();
                    if ($hasLines) {
                        $keeper = $acc;
                        break;
                    }
                }
                if ($keeper === null) {
                    $keeper = $accounts->first();
                }

                foreach ($accounts as $acc) {
                    if ($acc->id === $keeper->id) {
                        continue;
                    }

                    // Re-point child accounts
                    DB::table('accounts')
                        ->where('parent_account_id', $acc->id)
                        ->update(['parent_account_id' => $keeper->id]);

                    // Re-point journal lines if any
                    DB::table('journal_lines')
                        ->where('account_id', $acc->id)
                        ->update(['account_id' => $keeper->id]);

                    // Delete duplicate
                    DB::table('accounts')->where('id', $acc->id)->delete();
                }
            }
        }

        // 2. Drop unit_id column and re-enforce unique constraint
        if (DB::getDriverName() === 'sqlite') {
            // If already migrated or table rebuilt, verify unique index
            if (Schema::hasColumn('accounts', 'unit_id')) {
                DB::statement('PRAGMA foreign_keys = OFF;');
                Schema::create('accounts_temp', function (Blueprint $t) {
                    $t->uuid('id')->primary();
                    $t->foreignUuid('chart_of_accounts_id')->constrained('chart_of_accounts')->cascadeOnDelete();
                    $t->string('account_code');
                    $t->string('name');
                    $t->string('type');
                    $t->string('section', 32)->nullable();
                    $t->string('currency', 3)->default('LYD');
                    $t->foreignUuid('parent_account_id')->nullable()->constrained('accounts_temp')->nullOnDelete();
                    $t->timestamps();
                    $t->unique(['chart_of_accounts_id', 'account_code'], 'accounts_chart_code_unique');
                });
                DB::statement('INSERT INTO accounts_temp (id, chart_of_accounts_id, account_code, name, type, section, currency, parent_account_id, created_at, updated_at) SELECT id, chart_of_accounts_id, account_code, name, type, section, currency, parent_account_id, created_at, updated_at FROM accounts');
                Schema::drop('accounts');
                Schema::rename('accounts_temp', 'accounts');
                DB::statement('PRAGMA foreign_keys = ON;');
            } else {
                // Ensure unique index exists
                try {
                    Schema::table('accounts', function (Blueprint $t) {
                        $t->unique(['chart_of_accounts_id', 'account_code'], 'accounts_chart_code_unique');
                    });
                } catch (Throwable) {
                    // Index already exists
                }
            }
        } else {
            Schema::table('accounts', function (Blueprint $t) {
                try {
                    $t->dropUnique('accounts_coa_unit_code_unique');
                } catch (Throwable) {
                    // Ignore if already dropped
                }
                $t->dropForeign(['unit_id']);
                $t->dropColumn('unit_id');
                $t->unique(['chart_of_accounts_id', 'account_code'], 'accounts_chart_code_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropUnique('accounts_chart_code_unique');
            $t->foreignUuid('unit_id')
                ->nullable()
                ->after('chart_of_accounts_id')
                ->constrained('operating_units')
                ->nullOnDelete();
            $t->unique(['chart_of_accounts_id', 'unit_id', 'account_code'], 'accounts_coa_unit_code_unique');
        });
    }
};
