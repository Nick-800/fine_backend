<?php

declare(strict_types=1);

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Wave 4 (account code rename): strip trailing zeros from every
     * account_code so the chart reads 1000 → 1, 1100 → 11, 1121 stays
     * 1121, 1110 → 111, etc. Parent/child relationships are preserved by
     * parent_account_id (UUID) and unaffected by the rename.
     *
     * Idempotent: a no-op on a DB that already has the new codes.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // RTRIM is built into SQLite; a single UPDATE covers every row.
            DB::statement("UPDATE accounts SET account_code = RTRIM(account_code, '0') WHERE account_code LIKE '%0'");
        } elseif ($driver === 'pgsql') {
            DB::statement("UPDATE accounts SET account_code = REGEXP_REPLACE(account_code, '0+$', '') WHERE account_code ~ '0$'");
        } elseif ($driver === 'mysql') {
            DB::statement("UPDATE accounts SET account_code = REGEXP_REPLACE(account_code, '0+$', '') WHERE account_code REGEXP '0$'");
        } else {
            // Fallback: process per-row in PHP. Eloquent handles the
            // unique-constraint check on each save.
            Account::query()
                ->where('account_code', 'like', '%0')
                ->get(['id', 'account_code'])
                ->each(function (Account $account): void {
                    $new = preg_replace('/0+$/', '', $account->account_code);
                    if ($new !== $account->account_code) {
                        $account->account_code = $new;
                        $account->save();
                    }
                });
        }
    }

    public function down(): void
    {
        // Re-padding every short code to 4 digits by suffixing zeros
        // restores the old numbering (last-3-digit codes like 1121 don't
        // pad because they don't end in 0). Lossy in one direction
        // (run forward more than once and you can't recover), so use with
        // care — only intended to roll back a deployment.
        $pad = static function (string $code): string {
            if ($code === '' || str_ends_with($code, '0') || strlen($code) >= 4) {
                return $code;
            }

            return str_pad($code, 4, '0', STR_PAD_RIGHT);
        };

        Account::query()->get(['id', 'account_code'])->each(function (Account $account) use ($pad): void {
            $restored = $pad($account->account_code);
            if ($restored !== $account->account_code) {
                $account->account_code = $restored;
                $account->save();
            }
        });
    }
};
