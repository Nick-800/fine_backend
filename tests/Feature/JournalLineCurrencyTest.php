<?php

declare(strict_types=1);

use App\Enums\InventoryEventType;
use App\Enums\PaymentRoute;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\PurchaseOrderStateService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Database\Seeders\SystemBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $makeUser = function (string $slug): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

        return $user;
    };

    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');
    $this->accounting = app(AccountingService::class);
});

test('a line with currency USD, original 100, no rate, LYD 480 stores fx_rate 4.8', function (): void {
    $entry = $this->accounting->postJournal('USD posting without explicit rate', [
        ['account_code' => '54', 'debit' => 480.0, 'currency' => 'USD', 'original_amount' => 100.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 480.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $line = $entry->lines->firstWhere('account_id', Account::where('account_code', '54')->value('id'));

    expect($line->currency)->toBe('USD')
        ->and((float) $line->original_amount)->toBe(100.0)
        ->and((float) $line->fx_rate)->toBe(4.8);
});

test('explicit rate that disagrees by more than 0.01 throws LedgerRuleException and writes nothing', function (): void {
    try {
        $this->accounting->postJournal('Mismatched rate', [
            ['account_code' => '54', 'debit' => 480.0, 'currency' => 'USD', 'original_amount' => 100.0, 'fx_rate' => 5.5, 'operating_unit_id' => $this->unit->id],
            ['account_code' => '223', 'credit' => 480.0, 'operating_unit_id' => $this->unit->id],
        ]);
        test()->fail('Expected LedgerRuleException was not thrown');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('FX_AMOUNT_MISMATCH')
            ->and($e->context['currency'])->toBe('USD')
            ->and((float) $e->context['original_amount'])->toBe(100.0)
            ->and((float) $e->context['fx_rate'])->toBe(5.5)
            ->and((float) $e->context['amount'])->toBe(480.0);
    }

    expect(JournalEntry::count())->toBe(0)
        ->and(JournalLine::count())->toBe(0);
});

test('currency LYD stores nulls', function (): void {
    $entry = $this->accounting->postJournal('Functional currency line', [
        ['account_code' => '54', 'debit' => 480.0, 'currency' => 'LYD', 'original_amount' => 480.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 480.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $line = $entry->lines->firstWhere('account_id', Account::where('account_code', '54')->value('id'));

    expect($line->currency)->toBeNull()
        ->and($line->original_amount)->toBeNull()
        ->and($line->fx_rate)->toBeNull();
});

test('reversal copies currency, original_amount, and fx_rate onto mirror', function (): void {
    $original = $this->accounting->postJournal('Original USD line', [
        ['account_code' => '54', 'debit' => 480.0, 'currency' => 'USD', 'original_amount' => 100.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 480.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $reversal = $this->accounting->reverseEntry($original, null, 'Reversal reason', $this->owner->id);

    $mirrorLine = $reversal->lines->firstWhere('account_id', Account::where('account_code', '54')->value('id'));

    expect($mirrorLine->credit)->toBe('480.0000')
        ->and($mirrorLine->currency)->toBe('USD')
        ->and((float) $mirrorLine->original_amount)->toBe(100.0)
        ->and((float) $mirrorLine->fx_rate)->toBe(4.8);
});

test('manual entry via POST api/v1/journal-entries with a USD line round-trips', function (): void {
    $response = $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Manual USD adjustment',
        'lines' => [
            ['account_code' => '54', 'debit' => 480.0, 'currency' => 'USD', 'original_amount' => 100.0, 'operating_unit_id' => $this->unit->id],
            ['account_code' => '223', 'credit' => 480.0, 'operating_unit_id' => $this->unit->id],
        ],
    ])->assertStatus(201);

    $entryId = $response->json('id');
    $get = $this->actingAs($this->owner)->getJson("/api/v1/journal-entries/{$entryId}")->assertOk()->json();

    $usdLine = collect($get['lines'])->firstWhere('currency', 'USD');
    expect($usdLine)->not->toBeNull()
        ->and((float) $usdLine['original_amount'])->toBe(100.0)
        ->and((float) $usdLine['fx_rate'])->toBe(4.8);
});

test('account show returns currency_balances for 121105 after a USD debit and a smaller USD credit', function (): void {
    $account = Account::where('account_code', '121105')->sole();

    $this->accounting->postJournal('Deposit USD', [
        ['account_code' => '121105', 'debit' => 1000.0, 'currency' => 'USD', 'original_amount' => 200.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 1000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $this->accounting->postJournal('Withdraw USD', [
        ['account_code' => '54', 'debit' => 250.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '121105', 'credit' => 250.0, 'currency' => 'USD', 'original_amount' => 50.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $response = $this->actingAs($this->owner)->getJson("/api/v1/accounts/{$account->id}")->assertOk()->json();

    $currencyBalances = $response['data']['currency_balances'];
    expect($currencyBalances)->toHaveCount(1)
        ->and($currencyBalances[0]['currency'])->toBe('USD')
        ->and((float) $currencyBalances[0]['original_balance'])->toBe(150.0)
        ->and((float) $currencyBalances[0]['lyd_balance'])->toBe(750.0);
});

test('ledger with currency parameter gives opening running closing in USD', function (): void {
    $account = Account::where('account_code', '121105')->sole();

    // Prior period movement (opening): 100 USD = 500 LYD on 2026-09-15
    $this->accounting->postJournal('Opening USD deposit', [
        ['account_code' => '121105', 'debit' => 500.0, 'currency' => 'USD', 'original_amount' => 100.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-09-15');

    // Period movement 1: debit 200 USD on 2026-10-05
    $this->accounting->postJournal('October USD deposit', [
        ['account_code' => '121105', 'debit' => 1000.0, 'currency' => 'USD', 'original_amount' => 200.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '223', 'credit' => 1000.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-10-05');

    // Period movement 2: credit 50 USD on 2026-10-15
    $this->accounting->postJournal('October USD withdrawal', [
        ['account_code' => '54', 'debit' => 250.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '121105', 'credit' => 250.0, 'currency' => 'USD', 'original_amount' => 50.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-10-15');

    $s = $this->actingAs($this->owner)
        ->getJson("/api/v1/accounts/{$account->id}/ledger?company_wide=1&from=2026-10-01&to=2026-10-31&currency=USD")
        ->assertOk()->json();

    expect((float) $s['summary']['opening_balance'])->toBe(100.0)
        ->and((float) $s['summary']['period_debit'])->toBe(200.0)
        ->and((float) $s['summary']['period_credit'])->toBe(50.0)
        ->and((float) $s['summary']['closing_balance'])->toBe(250.0)
        ->and($s['summary']['currency'])->toBe('USD')
        ->and($s['data'])->toHaveCount(2)
        ->and((float) $s['data'][0]['running_balance'])->toBe(300.0)
        ->and((float) $s['data'][1]['running_balance'])->toBe(250.0)
        ->and($s['data'][0]['currency'])->toBe('USD')
        ->and((float) $s['data'][0]['original_amount'])->toBe(200.0)
        ->and((float) $s['data'][0]['fx_rate'])->toBe(5.0);
});

test('backfill command dry-run writes nothing and real run stamps PaymentRequest supplier line', function (): void {
    $supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foreign Chem Co',
        'default_currency' => 'USD',
    ]);

    $stateService = app(PurchaseOrderStateService::class);
    $order = PurchaseOrder::create([
        'operating_unit_id' => $this->unit->id,
        'supplier_id' => $supplier->id,
        'currency' => 'USD',
        'negotiated_price' => 100.0,
        'quantity' => 10.0,
        'booked_fx_rate' => 5.0,
        'status' => PurchaseOrderStatus::Draft,
    ]);

    $stateService->transitionToPendingPayment($order);
    $stateService->selectPaymentRoute($order->fresh(), PaymentRoute::Market, 1000.0);
    $paymentRequest = $order->paymentRequests()->first();
    $cashAccountId = Account::where('account_code', '121101')->value('id');
    $stateService->executePayment($paymentRequest, 5.0, paymentSourceAccountId: $cashAccountId);

    $entry = JournalEntry::where('source_document_type', 'PaymentRequest')
        ->where('source_document_id', $paymentRequest->id)
        ->firstOrFail();

    $supplierLine = $entry->lines()->where('debit', '>', 0)->firstOrFail();
    expect($supplierLine->currency)->toBe('USD');

    // Reset line currency columns to null using direct DB update
    DB::table('journal_lines')->where('id', $supplierLine->id)->update([
        'currency' => null,
        'original_amount' => null,
        'fx_rate' => null,
    ]);

    // Run with --dry-run
    $this->artisan('ledger:backfill-line-currency', ['--dry-run' => true])
        ->expectsOutput('1 lines stamped, 0 skipped')
        ->assertSuccessful();

    $reloadedLine = DB::table('journal_lines')->where('id', $supplierLine->id)->first();
    expect($reloadedLine->currency)->toBeNull()
        ->and($reloadedLine->original_amount)->toBeNull();

    // Run real backfill
    $this->artisan('ledger:backfill-line-currency')
        ->expectsOutput('1 lines stamped, 0 skipped')
        ->assertSuccessful();

    $stampedLine = DB::table('journal_lines')->where('id', $supplierLine->id)->first();
    expect($stampedLine->currency)->toBe('USD')
        ->and((float) $stampedLine->original_amount)->toBe(1000.0)
        ->and((float) $stampedLine->fx_rate)->toBe(5.0);

    // Run again: idempotent, 1 skipped
    $this->artisan('ledger:backfill-line-currency')
        ->expectsOutput('0 lines stamped, 1 skipped')
        ->assertSuccessful();
});

test('TransportIn maps to 406 after SystemBootstrapSeeder', function (): void {
    $this->seed(SystemBootstrapSeeder::class);

    $acc406 = Account::where('account_code', '406')->firstOrFail();
    $links = OperatingUnitAccount::where('event_type', InventoryEventType::TransportIn)->get();

    expect($links)->not->toBeEmpty();
    foreach ($links as $link) {
        expect($link->account_id)->toBe($acc406->id);
    }
});
