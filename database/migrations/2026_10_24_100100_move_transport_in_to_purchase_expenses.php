<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-point inventory transport_in events from FX Loss (53) to Purchase Expenses (406).
     */
    public function up(): void
    {
        $hasCompany = DB::table('companies')->exists();
        if (! $hasCompany) {
            return;
        }

        $acc53Id = DB::table('accounts')->where('account_code', '53')->value('id');
        $acc406Id = DB::table('accounts')->where('account_code', '406')->value('id');

        if ($acc53Id === null || $acc406Id === null) {
            return;
        }

        DB::table('operating_unit_accounts')
            ->where('event_type', 'transport_in')
            ->where('account_id', $acc53Id)
            ->update(['account_id' => $acc406Id]);
    }

    public function down(): void
    {
        // No-op: migrating transport_in back to FX Loss (53) would restore an invalid account mapping.
    }
};
