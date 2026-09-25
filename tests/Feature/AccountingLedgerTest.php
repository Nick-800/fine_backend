<?php

declare(strict_types=1);

use App\Exceptions\MissingAccountException;
use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->accounting = app(AccountingService::class);
});

test('a balanced journal posts with both sides recorded', function () {
    $entry = $this->accounting->postJournal('Test posting', [
        ['account_code' => '1131', 'debit' => 500.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '1121', 'credit' => 500.0, 'operating_unit_id' => $this->unit->id],
    ]);

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->totalDebit())->toBe(500.0)
        ->and($entry->totalCredit())->toBe(500.0)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->reference)->toStartWith('JE-');
});

test('an unbalanced journal is refused and writes nothing', function () {
    // ACC-01. A half-written journal corrupts the trial balance permanently, so
    // it must never reach the database.
    expect(fn () => $this->accounting->postJournal('Lopsided', [
        ['account_code' => '1131', 'debit' => 500.0],
        ['account_code' => '1121', 'credit' => 400.0],
    ]))->toThrow(UnbalancedJournalException::class);

    expect(JournalEntry::count())->toBe(0);
});

test('a line cannot be both a debit and a credit', function () {
    expect(fn () => $this->accounting->postJournal('Confused line', [
        ['account_code' => '1131', 'debit' => 100.0, 'credit' => 100.0],
    ]))->toThrow(InvalidArgumentException::class);
});

test('negative amounts are refused', function () {
    expect(fn () => $this->accounting->postJournal('Negative', [
        ['account_code' => '1131', 'debit' => -100.0],
        ['account_code' => '1121', 'credit' => -100.0],
    ]))->toThrow(InvalidArgumentException::class);
});

test('a zero-value journal records nothing worth recording', function () {
    expect(fn () => $this->accounting->postJournal('Empty', [
        ['account_code' => '1131', 'debit' => 0],
        ['account_code' => '1121', 'credit' => 0],
    ]))->toThrow(InvalidArgumentException::class);
});

test('an unknown account code is refused rather than skipped', function () {
    expect(fn () => $this->accounting->postJournal('Bad account', [
        ['account_code' => '9999', 'debit' => 10.0],
        ['account_code' => '1121', 'credit' => 10.0],
    ]))->toThrow(MissingAccountException::class);

    expect(JournalEntry::count())->toBe(0);
});

test('closing a foam batch posts finished goods against work in process', function () {
    $blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'sku' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    // material_cost is seeded directly here (in place of a chemical
    // consumption report) purely to exercise the close-side posting.
    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'status' => 'running',
        'material_cost' => 2000.0,
    ]);

    foreach (['consumed', 'curing', 'ready_for_grading'] as $status) {
        ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => $status]);
    }

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 2, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'graded']);
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'closed'])
        ->assertStatus(200);

    $entries = ($this->api)()
        ->getJson("/api/v1/journal-entries/for-document/ProductionBatch/{$batch->id}")
        ->assertStatus(200)
        ->json();

    expect($entries)->toHaveCount(1);

    $lines = collect($entries[0]['lines']);
    $debit = $lines->firstWhere('debit', '2000.0000');
    $credit = $lines->firstWhere('credit', '2000.0000');

    expect($debit['account']['account_code'])->toBe('1131')   // Finished Goods — Foam Blocks
        ->and($credit['account']['account_code'])->toBe('1121'); // WIP — Foam Production
});

test('the trial balance stays in balance after a batch closes', function () {
    $this->accounting->postJournal('Opening', [
        ['account_code' => '1121', 'debit' => 2000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '21', 'credit' => 2000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $this->accounting->postJournal('Close', [
        ['account_code' => '1131', 'debit' => 2000.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '1121', 'credit' => 2000.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $tb = ($this->api)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();

    expect($tb['balanced'])->toBeTrue()
        ->and($tb['total_debit'])->toBe($tb['total_credit']);

    // WIP came in at 2000 and went straight back out, so it nets to zero.
    $wip = collect($tb['rows'])->firstWhere('account_code', '1121');
    expect((float) $wip['balance'])->toBe(0.0);
});

test('the chart of accounts lists every account with signed balances', function () {
    $this->accounting->postJournal('Purchase', [
        ['account_code' => '111', 'debit' => 800.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '21', 'credit' => 800.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $accounts = ($this->api)()->getJson('/api/v1/accounts')
        ->assertStatus(200)
        ->json('data');

    $byCode = collect($accounts)->keyBy('account_code');

    // Both sides carry a positive balance in their natural sign.
    expect((float) $byCode['111']['balance'])->toBe(800.0)
        ->and((float) $byCode['21']['balance'])->toBe(800.0)
        ->and($byCode['111']['parent_account_id'])->toBe($byCode['11']['id']);
});

test('an account ledger lists its lines newest first with entry context', function () {
    $this->accounting->postJournal('First', [
        ['account_code' => '111', 'debit' => 100.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '21', 'credit' => 100.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-08-01');
    $this->accounting->postJournal('Second', [
        ['account_code' => '111', 'debit' => 200.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '21', 'credit' => 200.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-08-10');

    $accountId = collect(($this->api)()->getJson('/api/v1/accounts')->json('data'))
        ->firstWhere('account_code', '111')['id'];

    $ledger = ($this->api)()->getJson("/api/v1/accounts/{$accountId}/ledger")
        ->assertStatus(200)
        ->json('data');

    expect($ledger)->toHaveCount(2)
        ->and((float) $ledger[0]['debit'])->toBe(200.0)
        ->and($ledger[0]['journal_entry']['description'])->toBe('Second')
        ->and((float) $ledger[1]['debit'])->toBe(100.0);
});

test('journal references do not collide', function () {
    $refs = collect(range(1, 5))->map(fn () => $this->accounting->postJournal('Entry', [
        ['account_code' => '1131', 'debit' => 1.0],
        ['account_code' => '1121', 'credit' => 1.0],
    ])->reference);

    expect($refs->unique())->toHaveCount(5);
});

test('an accounting manager or owner can create an account and sub-account', function () {
    $accountant = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    // Create top-level account
    $response = $this->actingAs($accountant)->postJson('/api/v1/accounts', [
        'account_code' => '6000',
        'name' => 'Other Expenses',
        'type' => 'expense',
    ])->assertStatus(201);

    expect($response->json('data.account_code'))->toBe('6000')
        ->and($response->json('data.name'))->toBe('Other Expenses')
        ->and($response->json('data.type'))->toBe('expense')
        ->and($response->json('data.parent_account_id'))->toBeNull();

    $parentId = $response->json('data.id');

    // Create child account under parent
    $childResponse = $this->actingAs($accountant)->postJson('/api/v1/accounts', [
        'account_code' => '6100',
        'name' => 'Marketing & Advertising',
        'type' => 'expense',
        'parent_account_id' => $parentId,
    ])->assertStatus(201);

    expect($childResponse->json('data.account_code'))->toBe('6100')
        ->and($childResponse->json('data.parent_account_id'))->toBe($parentId);
});

test('duplicate account code is rejected', function () {
    $accountant = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    // 1000 already seeded by ChartOfAccountsSeeder
    $this->actingAs($accountant)->postJson('/api/v1/accounts', [
        'account_code' => '1',
        'name' => 'Duplicate Assets',
        'type' => 'asset',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['account_code']);
});

test('child account must match parent account type', function () {
    $accountant = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);
    UserRole::create(['user_id' => $accountant->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $parent = Account::where('account_code', '5')->firstOrFail(); // Expense

    $this->actingAs($accountant)->postJson('/api/v1/accounts', [
        'account_code' => '5999',
        'name' => 'Mismatched Asset Under Expense',
        'type' => 'asset',
        'parent_account_id' => $parent->id,
    ])->assertStatus(422);
});

test('unauthorized users cannot create accounts', function () {
    // $this->user has 'foam_manager' role, which is not permitted to mutate financial accounts
    ($this->api)()->postJson('/api/v1/accounts', [
        'account_code' => '7000',
        'name' => 'Forbidden Account',
        'type' => 'expense',
    ])->assertStatus(403);
});

test('user can view account details with computed balances and parent', function () {
    $parent = Account::where('account_code', '1')->firstOrFail();
    $child = Account::where('account_code', '1131')->firstOrFail();

    $this->accounting->postJournal('Material purchase test', [
        ['account_code' => '1131', 'debit' => 1200.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '1121', 'credit' => 1200.0, 'operating_unit_id' => $this->unit->id],
    ]);

    $res = ($this->api)()->getJson("/api/v1/accounts/{$child->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'id',
                'account_code',
                'name',
                'type',
                'currency',
                'parent_account_id',
                'parent',
                'total_debit',
                'total_credit',
                'balance',
                'transaction_count',
                'created_at',
            ],
        ]);

    expect($res->json('data.account_code'))->toBe('1131')
        ->and((float) $res->json('data.total_debit'))->toEqual(1200.0)
        ->and((float) $res->json('data.total_credit'))->toEqual(0.0)
        ->and((float) $res->json('data.balance'))->toEqual(1200.0)
        ->and($res->json('data.transaction_count'))->toBe(1);
});

test('account ledger supports date range and text search filtering', function () {
    $account = Account::where('account_code', '1131')->firstOrFail();

    $entry1 = $this->accounting->postJournal('Alpha batch chemicals', [
        ['account_code' => '1131', 'debit' => 300.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'Polyol drums'],
        ['account_code' => '1121', 'credit' => 300.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-05-01');

    $entry2 = $this->accounting->postJournal('Beta batch pigments', [
        ['account_code' => '1131', 'debit' => 700.0, 'operating_unit_id' => $this->unit->id, 'memo' => 'Color additives'],
        ['account_code' => '1121', 'credit' => 700.0, 'operating_unit_id' => $this->unit->id],
    ], entryDate: '2026-06-15');

    // Date range filter: May only
    $resMay = ($this->api)()->getJson("/api/v1/accounts/{$account->id}/ledger?from=2026-05-01&to=2026-05-31")
        ->assertStatus(200);
    expect($resMay->json('total'))->toBe(1)
        ->and($resMay->json('data.0.journal_entry.reference'))->toBe($entry1->reference);

    // Date range filter: June only
    $resJune = ($this->api)()->getJson("/api/v1/accounts/{$account->id}/ledger?from=2026-06-01&to=2026-06-30")
        ->assertStatus(200);
    expect($resJune->json('total'))->toBe(1)
        ->and($resJune->json('data.0.journal_entry.reference'))->toBe($entry2->reference);

    // Search by description: 'pigments'
    $resSearchDesc = ($this->api)()->getJson("/api/v1/accounts/{$account->id}/ledger?search=pigments")
        ->assertStatus(200);
    expect($resSearchDesc->json('total'))->toBe(1)
        ->and($resSearchDesc->json('data.0.journal_entry.reference'))->toBe($entry2->reference);

    // Search by memo: 'Polyol'
    $resSearchMemo = ($this->api)()->getJson("/api/v1/accounts/{$account->id}/ledger?search=Polyol")
        ->assertStatus(200);
    expect($resSearchMemo->json('total'))->toBe(1)
        ->and($resSearchMemo->json('data.0.journal_entry.reference'))->toBe($entry1->reference);
});
