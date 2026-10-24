<?php

declare(strict_types=1);

use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PayableSettlement;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\PayableSettlementService;
use App\Services\TreasuryBalanceService;
use App\Support\CurrentUnitContext;
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

    $this->accounting = app(AccountingService::class);
    $this->balanceService = app(TreasuryBalanceService::class);
    $this->settlementService = app(PayableSettlementService::class);

    // Fund the bank treasury
    $this->accounting->postJournal('Fund bank treasury', [
        ['account_code' => '1212', 'debit' => 5000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '33', 'credit' => 5000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    // Accrue wages on account 226 for the unit
    $this->accounting->postJournal('Accrue payroll wages', [
        ['account_code' => '57', 'debit' => 1000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '226', 'credit' => 1000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('settling 226 by cheque posts DR 226 / CR 227, creates an issued cheque linked to settlement, lowers outstanding, leaves bank balance unchanged until clearing; clearing then posts DR 227 / CR bank', function (): void {
    $initialBankBalance = $this->balanceService->balanceFor($this->bankTreasury)['ledger_balance'];
    expect($initialBankBalance)->toBe(5000.0);

    $initialOutstanding = $this->settlementService->outstanding('226', $this->unit->id);
    expect($initialOutstanding)->toBe(1000.0);

    $payload = [
        'account_code' => '226',
        'amount' => 400.0,
        'method' => 'cheque',
        'reference' => 'PAY-WAGES-01',
        'operating_unit_id' => $this->unit->id,
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-WAGES-01',
            'due_date' => '2026-11-01',
            'issue_date' => '2026-10-20',
            'payee_name' => 'Wages Distribution',
        ],
    ];

    $response = ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(201);

    $response->assertJsonPath('account_code', '226')
        ->assertJsonPath('method', 'cheque')
        ->assertJsonPath('reference', 'PAY-WAGES-01')
        ->assertJsonPath('amount', fn ($v): bool => (float) $v === 400.0)
        ->assertJsonPath('cheque.cheque_no', 'OUT-WAGES-01')
        ->assertJsonPath('cheque.status', 'issued')
        ->assertJsonPath('cheque.status_label', 'صادر')
        ->assertJsonPath('cheque.payee_name', 'Wages Distribution')
        ->assertJsonPath('cheque.amount', fn ($v): bool => (float) $v === 400.0);

    $settlement = PayableSettlement::with('cheque')->sole();
    expect($settlement->method)->toBe('cheque')
        ->and((float) $settlement->amount)->toBe(400.0)
        ->and($settlement->cheque_id)->not->toBeNull()
        ->and($settlement->cheque)->not->toBeNull();

    $cheque = $settlement->cheque;
    expect($cheque->cheque_no)->toBe('OUT-WAGES-01')
        ->and($cheque->status)->toBe(ChequeStatus::Issued)
        ->and($cheque->counter_account_code)->toBe('226')
        ->and((float) $cheque->amount)->toBe(400.0)
        ->and($cheque->deposit_cash_account_id)->toBe($this->bankTreasury->id)
        ->and($cheque->notes)->toBe('PAY-WAGES-01');

    // Issuance entry: DR 226 / CR 227 (no bank entry)
    $issueEntry = JournalEntry::where('source_document_type', 'Cheque')
        ->where('source_document_id', $cheque->id)
        ->sole();
    $lines = $issueEntry->lines()->with('account')->get();

    $wagesLine = $lines->firstWhere(fn ($l): bool => $l->account->account_code === '226');
    $notesPayableLine = $lines->firstWhere(fn ($l): bool => $l->account->account_code === '227');

    expect($wagesLine)->not->toBeNull()
        ->and((float) $wagesLine->debit)->toBe(400.0)
        ->and($notesPayableLine)->not->toBeNull()
        ->and((float) $notesPayableLine->credit)->toBe(400.0);

    // Outstanding of 226 drops immediately
    expect($this->settlementService->outstanding('226', $this->unit->id))->toBe(600.0);

    // Bank balance remains unchanged before clearing
    $bankBalanceBeforeClearing = $this->balanceService->balanceFor($this->bankTreasury)['ledger_balance'];
    expect($bankBalanceBeforeClearing)->toBe(5000.0);

    // Clear the cheque
    $clearResponse = ($this->asOwner)()
        ->postJson("/api/v1/cheques/{$cheque->id}/clear", [
            'date' => '2026-10-20',
            'note' => 'Cheque cleared at bank',
        ])
        ->assertOk();

    $clearResponse->assertJsonPath('data.status', 'cleared')
        ->assertJsonPath('data.status_label', 'محصّل');

    $cheque->refresh();
    expect($cheque->status)->toBe(ChequeStatus::Cleared);

    // Clearing entry: DR 227 / CR bank (1212)
    $bankBalanceAfterClearing = $this->balanceService->balanceFor($this->bankTreasury)['ledger_balance'];
    expect($bankBalanceAfterClearing)->toBe(4600.0);

    // 227 notes payable balance is cleared
    expect($this->settlementService->outstanding('227', $this->unit->id))->toBe(0.0);
});

test('settling by cheque defaults payee_name to payable account name if omitted', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 150.0,
        'method' => 'cheque',
        'operating_unit_id' => $this->unit->id,
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-DEFAULT-PAYEE',
            'due_date' => '2026-11-05',
        ],
    ];

    $response = ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(201);

    $expectedAccountName = Account::where('account_code', '226')->value('name');
    expect($expectedAccountName)->not->toBeNull();

    $response->assertJsonPath('cheque.payee_name', $expectedAccountName);

    $cheque = Cheque::sole();
    expect($cheque->payee_name)->toBe($expectedAccountName);
});

test('amount above outstanding refused and nothing written', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 1200.0,
        'method' => 'cheque',
        'operating_unit_id' => $this->unit->id,
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-OVER-01',
            'due_date' => '2026-11-01',
        ],
    ];

    ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SETTLEMENT');

    expect(PayableSettlement::count())->toBe(0)
        ->and(Cheque::count())->toBe(0);
});

test('non-bank treasury refused (CHEQUE_BANK_REQUIRED)', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 200.0,
        'method' => 'cheque',
        'operating_unit_id' => $this->unit->id,
        'cheque' => [
            'cash_account_id' => $this->cashTreasury->id,
            'cheque_no' => 'OUT-NON-BANK-01',
            'due_date' => '2026-11-01',
        ],
    ];

    ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'CHEQUE_BANK_REQUIRED');

    expect(PayableSettlement::count())->toBe(0)
        ->and(Cheque::count())->toBe(0);
});

test('operating unit required if none provided or in context (OPERATING_UNIT_REQUIRED)', function (): void {
    app(CurrentUnitContext::class)->clear();

    $payload = [
        'account_code' => '226',
        'amount' => 200.0,
        'method' => 'cheque',
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-NO-UNIT-01',
            'due_date' => '2026-11-01',
        ],
    ];

    ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'OPERATING_UNIT_REQUIRED');

    expect(PayableSettlement::count())->toBe(0)
        ->and(Cheque::count())->toBe(0);
});

test('a company-wide cheque settlement keeps its company scope and tags the cheque with the current unit', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 200.0,
        'method' => 'cheque',
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-CONTEXT-01',
            'due_date' => '2026-11-01',
        ],
    ];

    $response = ($this->asOwner)()
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(201);

    // The cap and the settlement follow the requested (company-wide) scope.
    $response->assertJsonPath('operating_unit_id', null);

    $settlement = PayableSettlement::sole();
    expect($settlement->operating_unit_id)->toBeNull()
        ->and($settlement->cheque->operating_unit_id)->toBe($this->unit->id);
});

test('cash settlement unchanged', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 100.0,
        'method' => 'cash',
        'cash_account_id' => $this->cashTreasury->id,
        'operating_unit_id' => $this->unit->id,
        'reference' => 'CASH-PAY-01',
    ];

    $response = ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(201);

    $response->assertJsonPath('method', 'cash')
        ->assertJsonPath('cheque_id', null)
        ->assertJsonPath('cheque', null)
        ->assertJsonPath('amount', fn ($v): bool => (float) $v === 100.0);

    $settlement = PayableSettlement::sole();
    expect($settlement->method)->toBe('cash')
        ->and($settlement->cheque_id)->toBeNull();

    expect(Cheque::count())->toBe(0);

    $entry = JournalEntry::where('source_document_type', 'PayableSettlement')
        ->where('source_document_id', $settlement->id)
        ->sole();
    $lines = $entry->lines()->with('account')->get();

    expect((float) $lines->firstWhere(fn ($l): bool => $l->account->account_code === '226')->debit)->toBe(100.0)
        ->and((float) $lines->firstWhere(fn ($l): bool => $l->account->account_code === '121101')->credit)->toBe(100.0);
});

test('roles unchanged: unauthorized user is rejected with SETTLEMENT_FORBIDDEN', function (): void {
    $payload = [
        'account_code' => '226',
        'amount' => 50.0,
        'method' => 'cheque',
        'operating_unit_id' => $this->unit->id,
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-ROLE-01',
            'due_date' => '2026-11-01',
        ],
    ];

    $this->actingAs($this->storeManager)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(403)
        ->assertJsonPath('code', 'SETTLEMENT_FORBIDDEN');

    expect(PayableSettlement::count())->toBe(0);

    // Accounting manager is authorized
    $this->actingAs($this->accountant)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/payable-settlements', $payload)
        ->assertStatus(201);

    expect(PayableSettlement::count())->toBe(1);
});

test('GET /payable-settlements includes method and cheque when present', function (): void {
    // Settle once by cheque
    ($this->asOwner)()->postJson('/api/v1/payable-settlements', [
        'account_code' => '226',
        'amount' => 200.0,
        'method' => 'cheque',
        'operating_unit_id' => $this->unit->id,
        'reference' => 'CHQ-REF-01',
        'cheque' => [
            'cash_account_id' => $this->bankTreasury->id,
            'cheque_no' => 'OUT-LIST-01',
            'due_date' => '2026-11-01',
        ],
    ])->assertStatus(201);

    // Settle once by cash
    ($this->asOwner)()->postJson('/api/v1/payable-settlements', [
        'account_code' => '226',
        'amount' => 100.0,
        'method' => 'cash',
        'cash_account_id' => $this->cashTreasury->id,
        'operating_unit_id' => $this->unit->id,
        'reference' => 'CASH-REF-01',
    ])->assertStatus(201);

    $response = ($this->asOwner)()->getJson('/api/v1/payable-settlements')
        ->assertStatus(200);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $chequeItem = collect($items)->firstWhere('method', 'cheque');
    expect($chequeItem)->not->toBeNull()
        ->and($chequeItem['method'])->toBe('cheque')
        ->and($chequeItem['cheque'])->not->toBeNull()
        ->and($chequeItem['cheque']['id'])->toBeString()
        ->and($chequeItem['cheque']['number'])->toBeString()
        ->and($chequeItem['cheque']['cheque_no'])->toBe('OUT-LIST-01')
        ->and($chequeItem['cheque']['status'])->toBe('issued')
        ->and($chequeItem['cheque']['status_label'])->toBe('صادر');

    $cashItem = collect($items)->firstWhere('method', 'cash');
    expect($cashItem)->not->toBeNull()
        ->and($cashItem['method'])->toBe('cash')
        ->and($cashItem['cheque'])->toBeNull();
});
