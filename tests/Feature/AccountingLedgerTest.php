<?php

declare(strict_types=1);

use App\Exceptions\MissingAccountException;
use App\Exceptions\UnbalancedJournalException;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\TankStock;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsSeeder::class);

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
    $chemical = InventoryItem::create([
        'name' => 'Polyol', 'sku' => 'CHEM-P', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'sku' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);
    TankStock::create([
        'chemical_inventory_item_id' => $chemical->id,
        'operating_unit_id' => $this->unit->id,
        'quantity_on_hand' => 5000,
        'weighted_avg_unit_cost' => 2.0,
    ]);

    $batch = ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'status' => 'running',
    ]);

    // 1000 kg @ 2.0 = 2000 material cost
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [['chemical_inventory_item_id' => $chemical->id, 'quantity_consumed' => 1000]],
    ])->assertStatus(201);

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
        ['account_code' => '2100', 'credit' => 2000.0, 'operating_unit_id' => $this->unit->id],
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

test('journal references do not collide', function () {
    $refs = collect(range(1, 5))->map(fn () => $this->accounting->postJournal('Entry', [
        ['account_code' => '1131', 'debit' => 1.0],
        ['account_code' => '1121', 'credit' => 1.0],
    ])->reference);

    expect($refs->unique())->toHaveCount(5);
});
