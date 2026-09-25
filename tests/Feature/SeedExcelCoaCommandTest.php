<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Database\Seeders\OperatingUnitChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Test Co', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);
});

test('coa:seed-excel imports unified company chart successfully', function () {
    $this->artisan('coa:seed-excel')
        ->expectsOutputToContain('Unified Chart of Accounts seeding completed')
        ->assertSuccessful();

    // The master foam chart has 492 accounts + existing standard mains
    $totalCount = Account::count();
    expect($totalCount)->toBeGreaterThanOrEqual(492);
});

test('coa:seed-excel supports --dataset filter option', function () {
    $this->artisan('coa:seed-excel', ['--dataset' => 'foam'])
        ->assertSuccessful();

    expect(Account::where('account_code', '1101')->first()->name)->toBe('أراضي');
});

test('coa:seed-excel is completely idempotent on repeated runs', function () {
    $this->artisan('coa:seed-excel')->assertSuccessful();

    $count1 = Account::count();

    // Re-run
    $this->artisan('coa:seed-excel')
        ->expectsOutputToContain('Unified Chart of Accounts seeding completed')
        ->assertSuccessful();

    $count2 = Account::count();
    expect($count2)->toBe($count1);
});

test('OperatingUnitChartSeeder wrapper triggers command correctly', function () {
    $this->seed(OperatingUnitChartSeeder::class);

    expect(Account::count())->toBeGreaterThanOrEqual(492);
});
