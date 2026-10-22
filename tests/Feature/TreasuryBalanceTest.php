<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use App\Services\TreasuryBalanceService;
use App\Support\LedgerAccounts;
use Database\Seeders\ChartOfAccountsTestSeeder;
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

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->accounting = app(AccountingService::class);
    $this->balanceService = app(TreasuryBalanceService::class);
});

test('a treasurys ledger_balance follows postings in cash-accounts and options', function (): void {
    $treasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Treasury',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121104')->sole()->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();
    $data = collect($response->json('data'))->firstWhere('id', $treasury->id);
    expect((float) $data['ledger_balance'])->toBe(0.0)
        ->and((float) $data['balance'])->toBe(0.0);

    // Post manual entry: DR 121104 (500) / CR 40201 (500)
    $this->accounting->postJournal('Cash deposit into main treasury', [
        ['account_code' => '121104', 'debit' => 500.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    // GET /cash-accounts shows updated ledger_balance
    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();
    $accountData = collect($response->json('data'))->firstWhere('id', $treasury->id);
    expect((float) $accountData['ledger_balance'])->toBe(500.0)
        ->and((float) $accountData['balance'])->toBe(0.0);

    // GET /cash-accounts/options returns ledger_balance
    $optionsResponse = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts/options')
        ->assertOk();
    $optionData = collect($optionsResponse->json('data'))->firstWhere('id', $treasury->id);
    expect((float) $optionData['ledger_balance'])->toBe(500.0);

    // Post a withdrawal: CR 121104 200.0 / DR 54 200.0
    $this->accounting->postJournal('Petty cash withdrawal', [
        ['account_code' => '54', 'debit' => 200.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '121104', 'debit' => 0.0, 'credit' => 200.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $responseAfter = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();
    $accountDataAfter = collect($responseAfter->json('data'))->firstWhere('id', $treasury->id);
    expect((float) $accountDataAfter['ledger_balance'])->toBe(300.0);
});

test('lines of a soft-deleted entry are excluded from treasury ledger balance', function (): void {
    $treasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Treasury',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121104')->sole()->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $entry = $this->accounting->postJournal('Entry to be deleted', [
        ['account_code' => '121104', 'debit' => 750.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 750.0, 'operating_unit_id' => $this->unit->id],
    ]);

    expect($this->balanceService->balanceFor($treasury)['ledger_balance'])->toBe(750.0);

    // Maintenance-level soft delete (ACC-13 model event refuses Eloquent delete)
    DB::table('journal_entries')->where('id', $entry->id)->update(['deleted_at' => now()]);

    expect($this->balanceService->balanceFor($treasury)['ledger_balance'])->toBe(0.0);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();
    $accountData = collect($response->json('data'))->firstWhere('id', $treasury->id);
    expect((float) $accountData['ledger_balance'])->toBe(0.0);
});

test('a USD treasury shows currency_balances USD original amount after a USD-stamped line', function (): void {
    $usdTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'FX Clearing Treasury',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121105')->sole()->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    // Post entry with USD-stamped line: 100 USD @ 4.8 = 480 LYD
    $this->accounting->postJournal('USD Treasury Intake', [
        [
            'account_code' => '121105',
            'debit' => 480.0,
            'credit' => 0.0,
            'currency' => 'USD',
            'original_amount' => 100.0,
            'fx_rate' => 4.8,
            'operating_unit_id' => $this->unit->id,
        ],
        [
            'account_code' => '40201',
            'debit' => 0.0,
            'credit' => 480.0,
            'operating_unit_id' => $this->unit->id,
        ],
    ]);

    $balance = $this->balanceService->balanceFor($usdTreasury);
    expect($balance['ledger_balance'])->toBe(480.0)
        ->and($balance['currency_balances'])->toHaveCount(1)
        ->and($balance['currency_balances'][0]['currency'])->toBe('USD')
        ->and((float) $balance['currency_balances'][0]['original_balance'])->toBe(100.0)
        ->and((float) $balance['currency_balances'][0]['lyd_balance'])->toBe(480.0);

    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();
    $data = collect($response->json('data'))->firstWhere('id', $usdTreasury->id);
    expect((float) $data['ledger_balance'])->toBe(480.0)
        ->and($data['currency_balances'])->toHaveCount(1)
        ->and($data['currency_balances'][0]['currency'])->toBe('USD')
        ->and((float) $data['currency_balances'][0]['original_balance'])->toBe(100.0);
});

test('the statement endpoint returns linked account lines and summary, or 422 TREASURY_NOT_LINKED', function (): void {
    $unlinked = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Unlinked Treasury',
        'kind' => 'cash',
        'account_id' => null,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    // Unlinked treasury returns 422 TREASURY_NOT_LINKED
    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson("/api/v1/cash-accounts/{$unlinked->id}/statement")
        ->assertStatus(422)
        ->assertJson([
            'code' => 'TREASURY_NOT_LINKED',
        ]);

    $linked = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Linked Treasury',
        'kind' => 'cash',
        'account_id' => Account::where('account_code', '121104')->sole()->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $this->accounting->postJournal('Sale deposit', [
        ['account_code' => '121104', 'debit' => 600.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'Memo 1'],
        ['account_code' => '40201', 'debit' => 0.0, 'credit' => 600.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-10-01');

    $this->accounting->postJournal('Expense payment', [
        ['account_code' => '54', 'debit' => 150.0, 'credit' => 0.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '121104', 'debit' => 0.0, 'credit' => 150.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'Memo 2'],
    ], entryDate: '2026-10-05');

    $statementResponse = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson("/api/v1/cash-accounts/{$linked->id}/statement?company_wide=1&from=2026-10-01&to=2026-10-31")
        ->assertOk();

    $json = $statementResponse->json();
    expect($json)->toHaveKeys(['data', 'summary', 'current_page', 'last_page', 'per_page', 'total'])
        ->and($json['total'])->toBe(2)
        ->and((float) $json['summary']['period_debit'])->toBe(600.0)
        ->and((float) $json['summary']['period_credit'])->toBe(150.0)
        ->and((float) $json['summary']['closing_balance'])->toBe(450.0)
        ->and($json['summary']['normal_side'])->toBe('debit');
});

test('the new wave-2 treasury accounts exist with the right type and parent after seeding', function (): void {
    // 125 under 12
    $acc125 = Account::where('account_code', '125')->first();
    expect($acc125)->not->toBeNull()
        ->and($acc125->name)->toBe('شيكات برسم التحصيل')
        ->and($acc125->type)->toBe('asset')
        ->and($acc125->parent?->account_code)->toBe('12');

    // 227 under 22
    $acc227 = Account::where('account_code', '227')->first();
    expect($acc227)->not->toBeNull()
        ->and($acc227->name)->toBe('أوراق الدفع')
        ->and($acc227->type)->toBe('liability')
        ->and($acc227->parent?->account_code)->toBe('22');

    // 44 under 4
    $acc44 = Account::where('account_code', '44')->first();
    expect($acc44)->not->toBeNull()
        ->and($acc44->name)->toBe('زيادة الخزائن')
        ->and($acc44->type)->toBe('revenue')
        ->and($acc44->parent?->account_code)->toBe('4');

    // 625 under 6
    $acc625 = Account::where('account_code', '625')->first();
    expect($acc625)->not->toBeNull()
        ->and($acc625->name)->toBe('عجز الخزائن')
        ->and($acc625->type)->toBe('expense')
        ->and($acc625->parent?->account_code)->toBe('6');
});

test('store and update return ledger_balance and currency_balances', function (): void {
    $leaf = Account::where('account_code', '121104')->sole();

    $storeRes = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/cash-accounts', [
            'operating_unit_id' => $this->unit->id,
            'name' => 'Store Test Treasury',
            'kind' => 'cash',
            'account_id' => $leaf->id,
        ])
        ->assertStatus(201);

    expect($storeRes->json('data'))->toHaveKeys(['ledger_balance', 'currency_balances'])
        ->and((float) $storeRes->json('data.ledger_balance'))->toBe(0.0)
        ->and($storeRes->json('data.currency_balances'))->toBeArray();

    $id = $storeRes->json('data.id');

    $updateRes = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson("/api/v1/cash-accounts/{$id}", [
            'name' => 'Renamed Test Treasury',
        ])
        ->assertOk();

    expect($updateRes->json('data'))->toHaveKeys(['ledger_balance', 'currency_balances'])
        ->and((float) $updateRes->json('data.ledger_balance'))->toBe(0.0)
        ->and($updateRes->json('data.currency_balances'))->toBeArray();
});

test('ledger accounts and document number constants are defined', function (): void {
    expect(LedgerAccounts::NOTES_RECEIVABLE)->toBe('124')
        ->and(LedgerAccounts::CHEQUES_UNDER_COLLECTION)->toBe('125')
        ->and(LedgerAccounts::NOTES_PAYABLE)->toBe('227')
        ->and(LedgerAccounts::CASH_OVERAGE)->toBe('44')
        ->and(LedgerAccounts::CASH_SHORTAGE)->toBe('625')
        ->and(DocumentNumberService::RECEIPT_VOUCHER)->toBe('RV')
        ->and(DocumentNumberService::PAYMENT_VOUCHER)->toBe('PV')
        ->and(DocumentNumberService::TREASURY_TRANSFER)->toBe('TRF')
        ->and(DocumentNumberService::CHEQUE)->toBe('CHQ');
});
