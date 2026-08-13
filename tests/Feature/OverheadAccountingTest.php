<?php

declare(strict_types=1);

use App\Enums\AllocationMethod;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocationRule;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\OverheadService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Foam Mfg', 'default_currency' => 'LYD', 'overhead_absorption_enabled' => false,
    ]);
    $this->seed(ChartOfAccountsSeeder::class);

    // Blueprints carry the production workflow that decides WIP absorption.
    $foamBlueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $storeBlueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => ['sales_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->foamUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $foamBlueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->storeUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $storeBlueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->overhead = app(OverheadService::class);

    $this->companyExpense = fn (float $amount = 900.0) => $this->overhead->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => null,
        'category' => 'electricity',
        'amount' => $amount,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'payable',
    ]);
});

test('recording a unit expense posts overhead against cash tagged to the unit', function () {
    $expense = $this->overhead->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->foamUnit->id,
        'category' => 'maintenance',
        'description' => 'Mixer bearing replacement',
        'amount' => 350.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'cash',
    ]);

    $entry = JournalEntry::where('source_document_id', $expense->id)->sole();
    $lines = $entry->lines()->with('account')->get();

    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($debit->account->account_code)->toBe('5400')
        ->and((float) $debit->debit)->toBe(350.0)
        ->and($debit->operating_unit_id)->toBe($this->foamUnit->id)
        ->and($credit->account->account_code)->toBe('1200')
        ->and($credit->operating_unit_id)->toBe($this->foamUnit->id);
});

test('a company-wide payable expense credits accounts payable with no unit tag', function () {
    $response = $this->actingAs($this->owner)->postJson('/api/v1/overhead-expenses', [
        'category' => 'rent',
        'amount' => 5000,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'payable',
        'is_company_wide' => true,
    ])->assertStatus(201);

    expect($response->json('operating_unit_id'))->toBeNull();

    $entry = JournalEntry::where('source_document_id', $response->json('id'))->sole();
    $credit = $entry->lines()->with('account')->get()->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($credit->account->account_code)->toBe('2100')
        ->and($credit->operating_unit_id)->toBeNull();
});

test('even split distributes a company expense across all units', function () {
    $expense = ($this->companyExpense)(900.0);

    $this->overhead->allocate($expense, AllocationMethod::EvenSplit);

    $allocations = $expense->refresh()->allocations;

    expect($allocations)->toHaveCount(2)
        ->and((float) $allocations->sum('amount'))->toBe(900.0)
        ->and((float) $allocations->firstWhere('operating_unit_id', $this->foamUnit->id)->amount)->toBe(450.0)
        ->and($expense->status->value)->toBe('allocated');

    // The reclass journal moves 5400 from unallocated to the units.
    $reclass = JournalEntry::where('description', 'like', 'Overhead allocation%')->sole();
    $untaggedCredit = $reclass->lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect((float) $untaggedCredit->credit)->toBe(900.0)
        ->and($untaggedCredit->operating_unit_id)->toBeNull();
});

test('an expense cannot be allocated twice', function () {
    $expense = ($this->companyExpense)();
    $this->overhead->allocate($expense, AllocationMethod::EvenSplit);

    expect(fn () => $this->overhead->allocate($expense->refresh(), AllocationMethod::EvenSplit))
        ->toThrow(InvalidArgumentException::class);
});

test('a unit-specific expense refuses allocation', function () {
    $expense = $this->overhead->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->foamUnit->id,
        'category' => 'water',
        'amount' => 100.0,
        'expense_date' => now()->toDateString(),
        'payment_source' => 'cash',
    ]);

    expect(fn () => $this->overhead->allocate($expense, AllocationMethod::EvenSplit))
        ->toThrow(InvalidArgumentException::class);
});

test('manual percentages must sum to one hundred', function () {
    $expense = ($this->companyExpense)();

    expect(fn () => $this->overhead->allocate($expense, AllocationMethod::ManualPercentage, percentages: [
        $this->foamUnit->id => 60.0,
        $this->storeUnit->id => 30.0,
    ]))->toThrow(InvalidArgumentException::class);

    $this->overhead->allocate($expense->refresh(), AllocationMethod::ManualPercentage, percentages: [
        $this->foamUnit->id => 60.0,
        $this->storeUnit->id => 40.0,
    ]);

    $allocations = $expense->refresh()->allocations;

    expect((float) $allocations->firstWhere('operating_unit_id', $this->foamUnit->id)->amount)->toBe(540.0)
        ->and((float) $allocations->firstWhere('operating_unit_id', $this->storeUnit->id)->amount)->toBe(360.0);
});

test('headcount allocation weights by employees per unit', function () {
    // 2 foam workers, 1 store worker → 600 / 300 of a 900 expense.
    foreach ([[$this->foamUnit, 2], [$this->storeUnit, 1]] as [$unit, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $entity = Entity::create(['name' => "Worker {$unit->code} {$i}", 'entity_type' => 'individual']);
            Employee::create([
                'entity_id' => $entity->id,
                'operating_unit_id' => $unit->id,
                'job_title' => 'Operator',
                'pay_type' => 'monthly',
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ]);
        }
    }

    $expense = ($this->companyExpense)(900.0);
    $this->overhead->allocate($expense, AllocationMethod::HeadcountBased);

    $allocations = $expense->refresh()->allocations;

    expect((float) $allocations->firstWhere('operating_unit_id', $this->foamUnit->id)->amount)->toBe(600.0)
        ->and((float) $allocations->firstWhere('operating_unit_id', $this->storeUnit->id)->amount)->toBe(300.0);
});

test('usage allocation follows the measured figures', function () {
    $expense = ($this->companyExpense)(900.0);

    $this->overhead->allocate($expense, AllocationMethod::UsageBased, usage: [
        $this->foamUnit->id => 8000, // kWh
        $this->storeUnit->id => 1000,
    ]);

    $allocations = $expense->refresh()->allocations;

    expect((float) $allocations->firstWhere('operating_unit_id', $this->foamUnit->id)->amount)->toBe(800.0)
        ->and((float) $allocations->firstWhere('operating_unit_id', $this->storeUnit->id)->amount)->toBe(100.0);
});

test('with absorption on the manufacturing share moves into WIP and the store share stays expense', function () {
    // ACC-06: the company flag decides whether allocation reaches WIP.
    $this->company->update(['overhead_absorption_enabled' => true]);

    $expense = ($this->companyExpense)(900.0);
    $this->overhead->allocate($expense, AllocationMethod::EvenSplit);

    $absorption = JournalEntry::where('description', 'like', 'Overhead absorption%')->sole();
    $wipDebit = $absorption->lines()->with('account')->get()->firstWhere(fn ($l) => (float) $l->debit > 0);

    expect($wipDebit->account->account_code)->toBe('1121')
        ->and((float) $wipDebit->debit)->toBe(450.0)
        ->and($wipDebit->operating_unit_id)->toBe($this->foamUnit->id);

    $allocations = $expense->refresh()->allocations;

    expect($allocations->firstWhere('operating_unit_id', $this->foamUnit->id)->absorbed)->toBeTrue()
        ->and($allocations->firstWhere('operating_unit_id', $this->storeUnit->id)->absorbed)->toBeFalse();
});

test('with absorption off no WIP journal is posted', function () {
    $expense = ($this->companyExpense)();
    $this->overhead->allocate($expense, AllocationMethod::EvenSplit);

    expect(JournalEntry::where('description', 'like', 'Overhead absorption%')->exists())->toBeFalse()
        ->and($expense->refresh()->allocations->pluck('absorbed')->unique()->all())->toBe([false]);
});

test('allocation without a method falls back to the configured rule', function () {
    $expense = ($this->companyExpense)();

    // No rule configured → refused rather than guessed.
    expect(fn () => $this->overhead->allocate($expense))->toThrow(InvalidArgumentException::class);

    OverheadAllocationRule::create([
        'company_id' => $this->company->id, 'method' => 'even_split', 'is_active' => true,
    ]);

    $this->overhead->allocate($expense->refresh());

    expect($expense->refresh()->allocations)->toHaveCount(2);
});

test('the allocate endpoint exposes the flow and the trial balance stays balanced', function () {
    $this->company->update(['overhead_absorption_enabled' => true]);
    $expense = ($this->companyExpense)(750.5);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/overhead-expenses/{$expense->id}/allocate", ['method' => 'even_split'])
        ->assertStatus(200)
        ->assertJsonPath('status', 'allocated');

    $tb = $this->actingAs($this->owner)->getJson('/api/v1/reports/trial-balance')
        ->assertStatus(200)->json();

    expect($tb['balanced'])->toBeTrue();
});
