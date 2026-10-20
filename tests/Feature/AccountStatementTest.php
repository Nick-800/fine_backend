<?php

declare(strict_types=1);

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

    $accounting = app(AccountingService::class);
    // Five cash movements across two units and two months.
    foreach ([
        ['2026-08-15', 1000.0, 0.0, 'unitA', 'Opening float'],
        ['2026-09-02', 200.0, 0.0, 'unitA', 'Sale alpha'],
        ['2026-09-10', 0.0, 50.0, 'unitA', 'Petty cash'],
        ['2026-09-18', 300.0, 0.0, 'unitB', 'Sale beta'],
        ['2026-09-30', 0.0, 25.0, 'unitA', 'Fuel'],
    ] as [$date, $in, $out, $unit, $memo]) {
        $accounting->postJournal($memo, [
            ['account_code' => '121101', 'debit' => $in, 'credit' => $out, 'operating_unit_id' => $this->{$unit}->id, 'memo' => $memo],
            ['account_code' => $in > 0 ? '40201' : '54', 'debit' => $out, 'credit' => $in, 'operating_unit_id' => $this->{$unit}->id],
        ], entryDate: $date);
    }

    $this->cash = Account::where('account_code', '121101')->sole();
    $this->statement = fn (string $query = '') => $this->actingAs($this->owner)
        ->getJson("/api/v1/accounts/{$this->cash->id}/ledger?company_wide=1&{$query}")
        ->assertOk()->json();
});

test('the opening balance carries everything before from, and closing ties to the last running balance', function () {
    $s = ($this->statement)('from=2026-09-01&to=2026-09-30');

    expect((float) $s['summary']['opening_balance'])->toBe(1000.0)
        ->and((float) $s['summary']['period_debit'])->toBe(500.0)
        ->and((float) $s['summary']['period_credit'])->toBe(75.0)
        ->and((float) $s['summary']['closing_balance'])->toBe(1425.0)
        ->and($s['data'])->toHaveCount(4)
        ->and((float) $s['data'][0]['running_balance'])->toBe(1200.0)
        ->and((float) end($s['data'])['running_balance'])->toBe(1425.0);
});

test('the running balance carries across pages', function () {
    $page1 = ($this->statement)('per_page=2&page=1');
    $page2 = ($this->statement)('per_page=2&page=2');

    expect($page1['last_page'])->toBe(3)
        ->and((float) $page1['data'][1]['running_balance'])->toBe(1200.0)
        ->and((float) $page2['data'][0]['running_balance'])->toBe(1150.0);
});

test('a search hides lines without changing any balance', function () {
    $s = ($this->statement)('search=Fuel');

    expect($s['data'])->toHaveCount(1)
        ->and((float) $s['data'][0]['running_balance'])->toBe(1425.0)
        ->and((float) $s['summary']['closing_balance'])->toBe(1425.0);
});

test('a unit scope only counts that unit', function () {
    $s = $this->actingAs($this->owner)
        ->getJson("/api/v1/accounts/{$this->cash->id}/ledger?operating_unit_id={$this->unitB->id}")
        ->assertOk()->json();

    expect($s['data'])->toHaveCount(1)
        ->and((float) $s['summary']['closing_balance'])->toBe(300.0);
});

test('include_children covers the subtree of a header', function () {
    $header = Account::where('account_code', '1211')->sole();
    $s = $this->actingAs($this->owner)->getJson("/api/v1/accounts/{$header->id}/ledger?company_wide=1&include_children=1")
        ->assertOk()->json();

    expect($s['data'])->toHaveCount(5)
        ->and((float) $s['summary']['closing_balance'])->toBe(1425.0);
});

test('all=1 returns every line for print and CSV', function () {
    $s = ($this->statement)('all=1&per_page=2');

    expect($s['data'])->toHaveCount(5)
        ->and($s['last_page'])->toBe(1);
});

test('the account card shows the balance brought into the year and the current balance', function () {
    $this->travelTo('2026-10-15 10:00:00');
    app(AccountingService::class)->postJournal('Next year float', [
        ['account_code' => '121101', 'debit' => 10.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '31', 'credit' => 10.0, 'operating_unit_id' => $this->unitA->id],
    ], entryDate: '2027-01-05');
    $this->travelTo('2027-02-01 10:00:00');

    $card = $this->actingAs($this->owner)->getJson("/api/v1/accounts/{$this->cash->id}?company_wide=1")->assertOk()->json('data');

    expect((float) $card['fiscal_year_opening_balance'])->toBe(1425.0)
        ->and((float) $card['current_balance'])->toBe(1435.0);
});
