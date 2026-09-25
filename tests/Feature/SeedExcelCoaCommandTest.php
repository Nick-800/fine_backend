<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\UnitBlueprint;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Database\Seeders\OperatingUnitChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Test Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Test BP',
        'workflow_set' => '[]',
        'default_role_template' => '[]',
        'default_inventory_config' => '[]',
    ]);

    $this->foamUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'مصنع الإسفنج',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $this->cutterUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'قسم القص',
        'code' => 'CUT-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);
});

test('coa:seed-excel imports foam and cutter charts successfully', function () {
    $this->artisan('coa:seed-excel')
        ->expectsOutputToContain('Chart of Accounts seeding completed')
        ->assertSuccessful();

    $foamCount = Account::where('unit_id', $this->foamUnit->id)->count();
    $cutterCount = Account::where('unit_id', $this->cutterUnit->id)->count();

    expect($foamCount)->toBe(492)
        ->and($cutterCount)->toBe(333);
});

test('coa:seed-excel supports --unit filter option', function () {
    $this->artisan('coa:seed-excel', ['--unit' => 'foam'])
        ->assertSuccessful();

    expect(Account::where('unit_id', $this->foamUnit->id)->count())->toBe(492)
        ->and(Account::where('unit_id', $this->cutterUnit->id)->count())->toBe(0);

    $this->artisan('coa:seed-excel', ['--unit' => 'cutter'])
        ->assertSuccessful();

    expect(Account::where('unit_id', $this->cutterUnit->id)->count())->toBe(333);
});

test('coa:seed-excel is completely idempotent on repeated runs', function () {
    $this->artisan('coa:seed-excel')->assertSuccessful();

    // Re-run
    $this->artisan('coa:seed-excel')
        ->expectsOutputToContain('Chart of Accounts seeding completed')
        ->assertSuccessful();

    expect(Account::where('unit_id', $this->foamUnit->id)->count())->toBe(492)
        ->and(Account::where('unit_id', $this->cutterUnit->id)->count())->toBe(333);
});

test('OperatingUnitChartSeeder wrapper triggers command correctly', function () {
    $this->seed(OperatingUnitChartSeeder::class);

    expect(Account::where('unit_id', $this->foamUnit->id)->count())->toBe(492)
        ->and(Account::where('unit_id', $this->cutterUnit->id)->count())->toBe(333);
});
