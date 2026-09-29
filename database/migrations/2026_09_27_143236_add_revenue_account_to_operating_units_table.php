<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            // The unit's own sales revenue account (e.g. 40202 مبيعات — مقص فاين).
            // Null falls back to the 41 header — see App\Support\SalesAccounts.
            $table->foreignUuid('revenue_account_id')->nullable()->after('manager_user_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revenue_account_id');
        });
    }
};
