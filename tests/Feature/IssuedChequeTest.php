<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\TreasuryBalanceService;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

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

    $makeUser = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        RolePermissions::grantDefaults($role);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');
    $this->treasuryOfficer = $makeUser('treasury-officer', $this->unit->id);
    $this->storeManager = $makeUser('store-manager', $this->unit->id);

    $this->cashTreasury = linkedTreasury($this->unit, '121101', 'cash');
    $this->bankTreasury = linkedTreasury($this->unit, '1212', 'bank');

    $this->supplierAccount = Account::where('account_code', '221')->first();
    $this->supplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'account_id' => $this->supplierAccount->id,
        'name' => 'Al-Safwa Raw Materials',
        'default_currency' => 'LYD',
    ]);

    $this->balanceService = app(TreasuryBalanceService::class);
    $this->accounting = app(AccountingService::class);

    $this->withHeader('X-Operating-Unit-ID', $this->unit->id);
});

test('issue to a supplier posts DR supplier account / CR 227', function (): void {
    $payload = [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-1001',
        'amount' => 500.0,
        'due_date' => '2026-11-05',
        'issue_date' => '2026-10-19',
        'supplier_id' => $this->supplier->id,
        'notes' => 'Payment for raw materials batch',
        'operating_unit_id' => $this->unit->id,
    ];

    $response = $this->actingAs($this->treasuryOfficer)
        ->postJson('/api/v1/cheques/issue', $payload)
        ->assertStatus(201);

    $response->assertJsonPath('data.number', fn ($v): bool => str_starts_with((string) $v, 'CHQ-2026-'))
        ->assertJsonPath('data.direction', 'outgoing')
        ->assertJsonPath('data.cheque_no', 'OUT-1001')
        ->assertJsonPath('data.bank_name', $this->bankTreasury->name)
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.status_label', 'صادر')
        ->assertJsonPath('data.amount', fn ($v): bool => (float) $v === 500.0)
        ->assertJsonPath('data.currency', 'LYD')
        ->assertJsonPath('data.issue_date', '2026-10-19')
        ->assertJsonPath('data.due_date', '2026-11-05')
        ->assertJsonPath('data.supplier.id', $this->supplier->id)
        ->assertJsonPath('data.supplier.name', 'Al-Safwa Raw Materials')
        ->assertJsonPath('data.counter_account.code', '221')
        ->assertJsonPath('data.deposit_cash_account.id', $this->bankTreasury->id)
        ->assertJsonPath('data.deposit_cash_account.name', $this->bankTreasury->name);

    $cheque = Cheque::where('cheque_no', 'OUT-1001')->firstOrFail();
    expect($cheque->issue_journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::with('lines.account')->find($cheque->issue_journal_entry_id);
    expect($entry)->not->toBeNull()
        ->and($entry->lines)->toHaveCount(2);

    $supplierLine = $entry->lines->firstWhere('account.account_code', '221');
    expect($supplierLine)->not->toBeNull()
        ->and((float) $supplierLine->debit)->toBe(500.0)
        ->and((float) $supplierLine->credit)->toBe(0.0);

    $payableLine = $entry->lines->firstWhere('account.account_code', '227');
    expect($payableLine)->not->toBeNull()
        ->and((float) $payableLine->debit)->toBe(0.0)
        ->and((float) $payableLine->credit)->toBe(500.0);

    $events = $cheque->events;
    expect($events)->toHaveCount(1);
    expect($events->first()->from_status)->toBeNull();
    expect($events->first()->to_status)->toBe(ChequeStatus::Issued);
    expect($events->first()->journal_entry_id)->toBe($entry->id);
    expect($events->first()->event_date->toDateString())->toBe('2026-10-19');
});

test('issue to a counter account with payee name', function (): void {
    $payload = [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-1002',
        'amount' => 250.0,
        'due_date' => '2026-11-10',
        'counter_account_code' => '54',
        'payee_name' => 'Tripoli Building Landlord',
        'operating_unit_id' => $this->unit->id,
    ];

    $response = $this->actingAs($this->treasuryOfficer)
        ->postJson('/api/v1/cheques/issue', $payload)
        ->assertStatus(201);

    $response->assertJsonPath('data.supplier', null)
        ->assertJsonPath('data.payee_name', 'Tripoli Building Landlord')
        ->assertJsonPath('data.counter_account.code', '54')
        ->assertJsonPath('data.amount', fn ($v): bool => (float) $v === 250.0);

    $cheque = Cheque::where('cheque_no', 'OUT-1002')->firstOrFail();
    $entry = JournalEntry::with('lines.account')->find($cheque->issue_journal_entry_id);

    $expenseLine = $entry->lines->firstWhere('account.account_code', '54');
    expect($expenseLine)->not->toBeNull()
        ->and((float) $expenseLine->debit)->toBe(250.0)
        ->and((float) $expenseLine->credit)->toBe(0.0);

    $payableLine = $entry->lines->firstWhere('account.account_code', '227');
    expect($payableLine)->not->toBeNull()
        ->and((float) $payableLine->credit)->toBe(250.0);
});

test('treasury counter account refused', function (): void {
    $response = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-BAD-COUNTER',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'counter_account_code' => '121101',
        'payee_name' => 'Cash Safe Treasury',
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'CHEQUE_COUNTER_IS_TREASURY');
    expect(Cheque::count())->toBe(0);
});

test('non-bank treasury refused', function (): void {
    $response = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->cashTreasury->id,
        'cheque_no' => 'OUT-NON-BANK',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'CHEQUE_BANK_REQUIRED');
    expect(Cheque::count())->toBe(0);
});

test('clear posts DR 227 / CR bank and the bank balance drops', function (): void {
    $this->accounting->postJournal('Fund bank balance', [
        ['account_code' => '1212', 'debit' => 1000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '33', 'credit' => 1000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $balanceBefore = $this->balanceService->balanceFor($this->bankTreasury);
    expect($balanceBefore['ledger_balance'])->toBe(1000.0);

    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-CLEAR-1',
        'amount' => 400.0,
        'due_date' => '2026-10-25',
        'issue_date' => '2026-10-18',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');

    $clearRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
        'note' => 'Bank debit confirmed on statement',
    ])->assertOk();

    $clearRes->assertJsonPath('data.status', 'cleared')
        ->assertJsonPath('data.status_label', 'محصّل');

    $balanceAfter = $this->balanceService->balanceFor($this->bankTreasury);
    expect($balanceAfter['ledger_balance'])->toBe(600.0);

    $cheque = Cheque::find($chequeId);
    expect($cheque->status)->toBe(ChequeStatus::Cleared);

    $clearEvent = $cheque->events()->where('to_status', ChequeStatus::Cleared->value)->first();
    expect($clearEvent)->not->toBeNull();

    $entry = JournalEntry::with('lines.account')->find($clearEvent->journal_entry_id);
    expect($entry)->not->toBeNull();

    $payableLine = $entry->lines->firstWhere('account.account_code', '227');
    expect($payableLine)->not->toBeNull()
        ->and((float) $payableLine->debit)->toBe(400.0)
        ->and((float) $payableLine->credit)->toBe(0.0);

    $bankLine = $entry->lines->firstWhere('account.account_code', '1212');
    expect($bankLine)->not->toBeNull()
        ->and((float) $bankLine->debit)->toBe(0.0)
        ->and((float) $bankLine->credit)->toBe(400.0);
});

test('insufficient bank balance refused with available', function (): void {
    $this->accounting->postJournal('Fund small balance', [
        ['account_code' => '1212', 'debit' => 50.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '33', 'credit' => 50.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-INSUFF-1',
        'amount' => 300.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');

    $response = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
    ])->assertStatus(422);

    $response->assertJsonPath('code', 'TREASURY_INSUFFICIENT_BALANCE')
        ->assertJsonPath('available', fn ($v): bool => (float) $v === 50.0);

    expect((float) $response->json('available'))->toBe(50.0);

    $cheque = Cheque::find($chequeId);
    expect($cheque->status)->toBe(ChequeStatus::Issued);
});

test('cancel reverses the issue entry and is terminal', function (): void {
    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-CANCEL-1',
        'amount' => 300.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');
    $cheque = Cheque::find($chequeId);
    $issueEntryId = $cheque->issue_journal_entry_id;

    $cancelRes = $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/cancel", [
        'reason' => 'Cheque was damaged before delivery',
    ])->assertOk();

    $cancelRes->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.status_label', 'ملغى');

    $reversalEntry = JournalEntry::where('reversal_of_id', $issueEntryId)->first();
    expect($reversalEntry)->not->toBeNull();

    $cheque->refresh();
    expect($cheque->status)->toBe(ChequeStatus::Cancelled);

    $cancelEvent = $cheque->events()->where('to_status', ChequeStatus::Cancelled->value)->first();
    expect($cancelEvent)->not->toBeNull()
        ->and($cancelEvent->journal_entry_id)->toBe($reversalEntry->id)
        ->and($cancelEvent->note)->toBe('Cheque was damaged before delivery');

    // Terminal: cannot clear or cancel again
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$chequeId}/cancel", [
        'reason' => 'Cancel again',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');
});

test('incoming actions on an outgoing cheque refused and vice versa', function (): void {
    $res = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-TRANS-TEST',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $outChequeId = $res->json('data.id');

    // Outgoing cheque cannot be deposited, bounced, or returned
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$outChequeId}/deposit", [
        'cash_account_id' => $this->bankTreasury->id,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$outChequeId}/bounce", [
        'reason' => 'Bounced outgoing cheque',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$outChequeId}/return", [
        'note' => 'Return outgoing cheque',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');

    // Create incoming cheque
    $inCheque = Cheque::create([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'cheque_number_internal' => 'CHQ-2026-99999',
        'direction' => 'incoming',
        'cheque_no' => 'IN-999',
        'bank_name' => 'Aman Bank',
        'amount' => 200.0,
        'currency' => 'LYD',
        'due_date' => '2026-10-25',
        'received_date' => '2026-10-18',
        'status' => ChequeStatus::InPortfolio,
    ]);

    // Incoming cheque cannot be cancelled via cancelIssued
    $this->actingAs($this->treasuryOfficer)->postJson("/api/v1/cheques/{$inCheque->id}/cancel", [
        'reason' => 'Attempt cancel on incoming',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CHEQUE_TRANSITION');
});

test('summary for direction=outgoing', function (): void {
    // Cheque 1: Due in 3 days (within 7 days)
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-SUM-1',
        'amount' => 100.0,
        'due_date' => '2026-10-23',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // Cheque 2: Overdue (due 2026-10-15)
    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-SUM-2',
        'amount' => 200.0,
        'due_date' => '2026-10-15',
        'issue_date' => '2026-10-14',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    // Cheque 3: Cleared
    $this->accounting->postJournal('Fund bank balance', [
        ['account_code' => '1212', 'debit' => 1000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '33', 'credit' => 1000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $res3 = $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-SUM-3',
        'amount' => 300.0,
        'due_date' => '2026-11-20',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/'.$res3->json('data.id').'/clear', [
        'date' => '2026-10-20',
    ])->assertOk();

    $summaryRes = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques/summary?direction=outgoing')
        ->assertOk();

    $summaryRes->assertJsonPath('data.due_within_7_days.count', 1)
        ->assertJsonPath('data.due_within_7_days.total', fn ($v): bool => (float) $v === 100.0)
        ->assertJsonPath('data.overdue.count', 1)
        ->assertJsonPath('data.overdue.total', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('data.by_status.issued.count', 2)
        ->assertJsonPath('data.by_status.issued.total', fn ($v): bool => (float) $v === 300.0)
        ->assertJsonPath('data.by_status.cleared.count', 1)
        ->assertJsonPath('data.by_status.cleared.total', fn ($v): bool => (float) $v === 300.0);

    // Default direction is incoming -> counts must be 0 for these outgoing cheques
    $incomingSummary = $this->actingAs($this->treasuryOfficer)->getJson('/api/v1/cheques/summary')
        ->assertOk();

    $incomingSummary->assertJsonPath('data.by_status.issued.count', 0)
        ->assertJsonPath('data.due_within_7_days.count', 0)
        ->assertJsonPath('data.overdue.count', 0);
});

test('roles: store-manager can read but not issue, clear or cancel', function (): void {
    $res = $this->actingAs($this->owner)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-ROLE-1',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(201);

    $chequeId = $res->json('data.id');

    // store-manager can read
    $this->actingAs($this->storeManager)->getJson('/api/v1/cheques/'.$chequeId)->assertOk();
    $this->actingAs($this->storeManager)->getJson('/api/v1/cheques/summary?direction=outgoing')->assertOk();

    // store-manager cannot issue
    $this->actingAs($this->storeManager)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-ROLE-FORBIDDEN',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $this->supplier->id,
    ])->assertStatus(403)->assertJsonPath('code', 'CHEQUE_FORBIDDEN');

    // store-manager cannot clear
    $this->actingAs($this->storeManager)->postJson("/api/v1/cheques/{$chequeId}/clear", [
        'date' => '2026-10-20',
    ])->assertStatus(403)->assertJsonPath('code', 'CHEQUE_FORBIDDEN');

    // store-manager cannot cancel
    $this->actingAs($this->storeManager)->postJson("/api/v1/cheques/{$chequeId}/cancel", [
        'reason' => 'Store manager cancel',
    ])->assertStatus(403)->assertJsonPath('code', 'CHEQUE_FORBIDDEN');
});

test('locked month refuses issue and leaves no row', function (): void {
    $this->accounting->postJournal('Seed September', [
        ['account_code' => '54', 'debit' => 10.0],
        ['account_code' => '223', 'credit' => 10.0],
    ], entryDate: '2026-09-15');

    $period = FiscalPeriod::query()
        ->where('calendar_year', 2026)
        ->where('calendar_month', 9)
        ->sole();

    $this->actingAs($this->accountant)
        ->postJson("/api/v1/fiscal-periods/{$period->id}/lock", ['include_earlier' => true])
        ->assertOk();

    $initialChequesCount = Cheque::count();

    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-LOCKED-MONTH',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'issue_date' => '2026-09-20',
        'supplier_id' => $this->supplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)->assertJsonPath('code', 'PERIOD_LOCKED');

    expect(Cheque::count())->toBe($initialChequesCount);
});

test('supplier without linked ledger account refused SUPPLIER_NOT_LINKED', function (): void {
    $unlinkedSupplier = Supplier::create([
        'operating_unit_id' => $this->unit->id,
        'account_id' => null,
        'name' => 'Unlinked Supplier Co',
        'default_currency' => 'LYD',
    ]);

    $this->actingAs($this->treasuryOfficer)->postJson('/api/v1/cheques/issue', [
        'cash_account_id' => $this->bankTreasury->id,
        'cheque_no' => 'OUT-UNLINKED-SUPPLIER',
        'amount' => 100.0,
        'due_date' => '2026-10-25',
        'supplier_id' => $unlinkedSupplier->id,
        'operating_unit_id' => $this->unit->id,
    ])->assertStatus(422)->assertJsonPath('code', 'SUPPLIER_NOT_LINKED');

    expect(Cheque::count())->toBe(0);
});
