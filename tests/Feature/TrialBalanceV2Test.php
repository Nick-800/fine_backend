<?php

declare(strict_types=1);

use App\Enums\JournalEntryType;
use App\Models\Account;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => $name, 'code' => $code, 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->unitA = $makeUnit('Foam Plant', 'FOAM-01');
    $this->unitB = $makeUnit('Cutter', 'CUT-01');

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $post = fn (string $date, string $debit, string $credit, float $amount, ?string $unit = null) => app(AccountingService::class)
        ->postJournal("Entry {$date}", [
            ['account_code' => $debit, 'debit' => $amount, 'operating_unit_id' => $unit],
            ['account_code' => $credit, 'credit' => $amount, 'operating_unit_id' => $unit],
        ], entryDate: $date);

    // September: opening capital and a sale. October: a sale and an expense.
    $post('2026-09-01', '121101', '31', 1000.0, $this->unitA->id);
    $post('2026-09-20', '121101', '40201', 300.0, $this->unitA->id);
    $post('2026-10-05', '121102', '40202', 200.0, $this->unitB->id);
    $post('2026-10-31', '54', '121101', 50.0, $this->unitA->id);

    $this->tb = fn (string $query = '') => $this->actingAs($this->owner)
        ->getJson("/api/v1/reports/trial-balance?company_wide=1&{$query}")
        ->assertOk()->json();
});

test('opening, movement and closing split at the from date and the to day is included', function () {
    $tb = ($this->tb)('from=2026-10-01&to=2026-10-31');
    $rows = collect($tb['rows'])->keyBy('account_code');

    expect((float) $rows['121101']['opening_debit'])->toBe(1300.0)
        ->and((float) $rows['121101']['period_credit'])->toBe(50.0)
        ->and((float) $rows['121101']['closing_debit'])->toBe(1250.0)
        ->and((float) $rows['40201']['opening_credit'])->toBe(300.0)
        ->and((float) $rows['40201']['period_credit'])->toBe(0.0)
        ->and((float) $rows['40202']['period_credit'])->toBe(200.0)
        ->and((float) $rows['54']['period_debit'])->toBe(50.0)
        ->and($tb['balanced'])->toBeTrue()
        ->and((float) $tb['totals']['period_debit'])->toBe(250.0)
        ->and((float) $tb['totals']['opening_debit'])->toBe(1300.0)
        ->and((float) $tb['totals']['opening_credit'])->toBe(1300.0);

    // `to` before the last entry leaves it out.
    $early = collect(($this->tb)('from=2026-10-01&to=2026-10-30')['rows'])->keyBy('account_code');
    expect($early->has('54'))->toBeFalse();
});

test('without dates it matches the all-time legacy numbers', function () {
    $tb = ($this->tb)();
    $rows = collect($tb['rows'])->keyBy('account_code');

    expect((float) $rows['121101']['debit'])->toBe(1300.0)
        ->and((float) $rows['121101']['credit'])->toBe(50.0)
        ->and((float) $rows['121101']['balance'])->toBe(1250.0)
        ->and((float) $tb['total_debit'])->toBe(1550.0)
        ->and($tb['balanced'])->toBeTrue();
});

test('zero accounts are hidden unless asked for', function () {
    $rows = collect(($this->tb)()['rows']);
    expect($rows->firstWhere('account_code', '1212'))->toBeNull();

    $withZero = collect(($this->tb)('include_zero=1')['rows']);
    expect($withZero->firstWhere('account_code', '1212'))->not->toBeNull()
        ->and($withZero->count())->toBeGreaterThan($rows->count());
});

test('the tree view rolls children up into their parents without changing totals', function () {
    $tb = ($this->tb)('view=tree');
    $rows = collect($tb['rows'])->keyBy('account_code');

    expect((float) $rows['1211']['closing_debit'])->toBe(1450.0)
        ->and((float) $rows['121']['closing_debit'])->toBe(1450.0)
        ->and($rows['1211']['is_leaf'])->toBeFalse()
        ->and($rows['121101']['level'])->toBeGreaterThan($rows['1211']['level'])
        ->and((float) $tb['totals']['period_debit'])->toBe(1550.0);
});

test('the level view shows accounts at that depth with their roll-ups', function () {
    $level = collect(($this->tb)('view=level&level=1')['rows']);

    expect($level->pluck('level')->unique()->values()->all())->toBe([1])
        ->and((float) $level->firstWhere('account_code', '1')['closing_debit'])->toBe(1450.0);

    $this->actingAs($this->owner)->getJson('/api/v1/reports/trial-balance?view=level')
        ->assertStatus(422)->assertJsonValidationErrors('level');
});

test('a unit scope only sums that unit, and a unit user cannot read another unit', function () {
    $rows = collect($this->actingAs($this->owner)
        ->getJson("/api/v1/reports/trial-balance?operating_unit_id={$this->unitB->id}")
        ->assertOk()->json('rows'))->keyBy('account_code');

    expect($rows->pluck('account_code')->sort(SORT_STRING)->values()->all())->toBe(['121102', '40202']);

    $manager = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Accounting', 'slug' => 'accounting-manager']);
    UserRole::create(['user_id' => $manager->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unitA->id]);

    $this->actingAs($manager)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/reports/trial-balance?scope=unit&unit_id={$this->unitB->id}")
        ->assertForbidden()->assertJsonPath('code', 'CROSS_UNIT_SCOPE');
});

test('closing entries can be left out to see balances before the close', function () {
    app(AccountingService::class)->postJournal('Year-end close', [
        ['account_code' => '40201', 'debit' => 300.0],
        ['account_code' => '33', 'credit' => 300.0],
    ], entryDate: '2026-10-31', entryType: JournalEntryType::Closing);

    $after = collect(($this->tb)()['rows'])->keyBy('account_code');
    $before = collect(($this->tb)('exclude_closing=1')['rows'])->keyBy('account_code');

    expect($after->has('40201') ? (float) $after['40201']['closing_balance'] : 0.0)->toBe(0.0)
        ->and((float) $before['40201']['closing_credit'])->toBe(300.0)
        ->and($before->has('33'))->toBeFalse();
});

test('a header holding lines of its own still shows in the leaf view', function () {
    app(AccountingService::class)->postJournal('Opening receivable', [
        ['account_code' => '122', 'debit' => 70.0],
        ['account_code' => '31', 'credit' => 70.0],
    ], isManual: true);

    $root = Account::where('account_code', '122')->sole();
    Account::create([
        'chart_of_accounts_id' => $root->chart_of_accounts_id, 'parent_account_id' => $root->id,
        'account_code' => '122001', 'name' => 'Client', 'type' => 'asset', 'currency' => 'LYD',
    ]);

    $tb = ($this->tb)();
    $row = collect($tb['rows'])->firstWhere('account_code', '122');

    expect($row)->not->toBeNull()
        ->and($row['is_leaf'])->toBeFalse()
        ->and($row['has_direct_lines'])->toBeTrue()
        ->and($tb['balanced'])->toBeTrue();
});
