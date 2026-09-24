<?php

declare(strict_types=1);

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

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'showroom',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Furniture Manager', 'slug' => 'furniture-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitA->id]);

    $accounting = app(AccountingService::class);

    // Old revenue in unit A, before the current period.
    $accounting->postJournal('Old sale', [
        ['account_code' => '13', 'debit' => 300.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '41', 'credit' => 300.0, 'operating_unit_id' => $this->unitA->id],
    ], entryDate: '2026-07-01');

    // Old purchase, assets and liabilities only.
    $accounting->postJournal('Old purchase', [
        ['account_code' => '111', 'debit' => 2000.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '21', 'credit' => 2000.0, 'operating_unit_id' => $this->unitA->id],
    ], entryDate: '2026-07-01');

    // Current period: unit A sells on credit and books its cost.
    $accounting->postJournal('Sale A', [
        ['account_code' => '13', 'debit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '41', 'credit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
    ]);
    $accounting->postJournal('COGS A', [
        ['account_code' => '51', 'debit' => 600.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '1131', 'credit' => 600.0, 'operating_unit_id' => $this->unitA->id],
    ]);

    // Unit B cash sale.
    $accounting->postJournal('Sale B', [
        ['account_code' => '12', 'debit' => 500.0, 'operating_unit_id' => $this->unitB->id],
        ['account_code' => '41', 'credit' => 500.0, 'operating_unit_id' => $this->unitB->id],
    ]);

    // Company-level expense carried by no unit.
    $accounting->postJournal('FX settlement loss', [
        ['account_code' => '53', 'debit' => 50.0],
        ['account_code' => '21', 'credit' => 50.0],
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('the income statement aggregates revenue and expenses into net income', function () {
    $report = ($this->asOwner)()->getJson('/api/v1/reports/income-statement')
        ->assertStatus(200)
        ->json();

    expect((float) $report['revenue']['total'])->toBe(1800.0)
        ->and((float) $report['expenses']['total'])->toBe(650.0)
        ->and((float) $report['net_income'])->toBe(1150.0);

    $sales = collect($report['revenue']['rows'])->firstWhere('account_code', '41');
    expect((float) $sales['balance'])->toBe(1800.0);
});

test('the income statement respects the period filter', function () {
    $report = ($this->asOwner)()->getJson('/api/v1/reports/income-statement?from=2026-08-01')
        ->assertStatus(200)
        ->json();

    expect((float) $report['revenue']['total'])->toBe(1500.0)
        ->and((float) $report['expenses']['total'])->toBe(650.0)
        ->and((float) $report['net_income'])->toBe(850.0);
});

test('the balance sheet balances by folding net income into equity', function () {
    $report = ($this->asOwner)()->getJson('/api/v1/reports/balance-sheet')
        ->assertStatus(200)
        ->json();

    // AR 1300 + Cash 500 + Raw 2000 - FG 600 = 3200 in assets.
    expect((float) $report['assets']['total'])->toBe(3200.0)
        ->and((float) $report['liabilities']['total'])->toBe(2050.0)
        ->and((float) $report['equity']['retained_current_period'])->toBe(1150.0)
        ->and((float) $report['equity']['total'])->toBe(1150.0)
        ->and($report['balanced'])->toBeTrue();
});

test('the balance sheet as of an earlier date excludes later postings', function () {
    $report = ($this->asOwner)()->getJson('/api/v1/reports/balance-sheet?as_of=2026-07-31')
        ->assertStatus(200)
        ->json();

    // Only the two July entries: AR 300 + Raw 2000 against AP 2000 + income 300.
    expect((float) $report['assets']['total'])->toBe(2300.0)
        ->and((float) $report['liabilities']['total'])->toBe(2000.0)
        ->and((float) $report['equity']['retained_current_period'])->toBe(300.0)
        ->and($report['balanced'])->toBeTrue();
});

test('unit profitability rolls up per unit with unallocated lines kept visible', function () {
    $report = ($this->asOwner)()->getJson('/api/v1/reports/unit-profitability')
        ->assertStatus(200)
        ->json();

    expect($report['rows'])->toHaveCount(3)
        ->and((float) $report['total_net'])->toBe(1150.0);

    $byName = collect($report['rows'])->keyBy('unit_name');

    expect((float) $byName['Furniture']['revenue'])->toBe(1300.0)
        ->and((float) $byName['Furniture']['expenses'])->toBe(600.0)
        ->and((float) $byName['Furniture']['net'])->toBe(700.0)
        ->and((float) $byName['Showroom']['net'])->toBe(500.0)
        ->and((float) $byName['unallocated']['net'])->toBe(-50.0);
});

test('a unit-scoped caller sees only their own income statement', function () {
    $report = $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/income-statement')
        ->assertStatus(200)
        ->json();

    expect((float) $report['revenue']['total'])->toBe(1300.0)
        ->and((float) $report['expenses']['total'])->toBe(600.0)
        ->and((float) $report['net_income'])->toBe(700.0);
});

test('a company-wide caller can bypass a pinned unit with company_wide=1', function () {
    // The desktop shell always pins a unit context — company_wide is how the
    // owner sees the whole ledger from the app.
    $scoped = ($this->asOwner)()
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/income-statement')
        ->assertStatus(200)->json();

    $companyWide = ($this->asOwner)()
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/income-statement?company_wide=1')
        ->assertStatus(200)->json();

    expect((float) $scoped['revenue']['total'])->toBe(1300.0)
        ->and((float) $companyWide['revenue']['total'])->toBe(1800.0);

    $entries = ($this->asOwner)()
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/journal-entries?company_wide=1')
        ->assertStatus(200)->json();

    expect($entries['total'])->toBe(6);
});

test('a unit manager cannot escape their unit with company_wide=1', function () {
    $report = $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson('/api/v1/reports/income-statement?company_wide=1')
        ->assertStatus(200)
        ->json();

    expect((float) $report['revenue']['total'])->toBe(1300.0)
        ->and((float) $report['net_income'])->toBe(700.0);
});

test('report date parameters are validated', function () {
    ($this->asOwner)()->getJson('/api/v1/reports/income-statement?from=not-a-date')
        ->assertStatus(422);

    ($this->asOwner)()->getJson('/api/v1/reports/balance-sheet?as_of=not-a-date')
        ->assertStatus(422);
});
