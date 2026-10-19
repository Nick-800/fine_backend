<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Support\ReceivableAccounts;
use App\Support\SalesAccounts;
use App\Support\UnitRevenueAccounts;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Postings no longer fall back to header accounts (12, 22, 41, 122) — the
 * ledger refuses them. Give existing databases what that needs:
 *
 *   - the new leaves: 121103–121105 treasuries, 225 payroll deductions,
 *     40203/40204 furniture and showroom sales;
 *   - a revenue account on every selling unit that has none, and the unit's
 *     revenue-type event mappings moved off the 41 header;
 *   - a receivable sub-account under 122 for every client without one;
 *   - the two bootstrap treasuries linked to their ledger leaves.
 *
 * Existing links are never overwritten. Fresh databases (no company yet) are
 * left for the normal seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Company::query()->doesntExist()) {
            return;
        }

        (new ChartOfAccountsSeeder)->run();

        UnitRevenueAccounts::linkDefaults();
        $this->moveRevenueEventsOffHeader();
        $this->linkBootstrapTreasuries();
        $this->provisionClientAccounts();
    }

    public function down(): void
    {
        // Accounts and links created here may already carry postings.
    }

    private function moveRevenueEventsOffHeader(): void
    {
        $headerId = Account::query()->where('account_code', '41')->value('id');

        if ($headerId === null) {
            return;
        }

        $revenueEvents = [
            InventoryEventType::Sales->value,
            InventoryEventType::SalesReturns->value,
            InventoryEventType::EarnedDiscount->value,
            InventoryEventType::GrantedDiscount->value,
            InventoryEventType::SalesCommission->value,
        ];

        OperatingUnit::withoutGlobalScopes()->whereNotNull('revenue_account_id')->each(
            fn (OperatingUnit $unit) => OperatingUnitAccount::query()
                ->where('operating_unit_id', $unit->id)
                ->whereIn('event_type', $revenueEvents)
                ->where('account_id', $headerId)
                ->update(['account_id' => $unit->revenue_account_id])
        );
    }

    private function linkBootstrapTreasuries(): void
    {
        foreach (['Main Operating Cash Treasury' => '121104', 'Foreign Exchange Clearing Safe' => '121105'] as $name => $code) {
            $accountId = Account::query()->where('account_code', $code)->value('id');

            if ($accountId !== null) {
                CashAccount::withoutGlobalScopes()
                    ->where('name', $name)
                    ->whereNull('account_id')
                    ->update(['account_id' => $accountId]);
            }
        }
    }

    private function provisionClientAccounts(): void
    {
        if (Account::query()->where('account_code', ReceivableAccounts::ROOT_CODE)->doesntExist()) {
            return;
        }

        Client::withoutGlobalScopes()
            ->whereNull('account_id')
            ->with('entity')
            ->each(fn (Client $client) => SalesAccounts::receivableFor($client));
    }
};
