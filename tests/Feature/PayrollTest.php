<?php

declare(strict_types=1);

use App\Models\Bom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\LaborLog;
use App\Models\LaborRoleRate;
use App\Models\OperatingUnit;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsSeeder::class);

    $blueprintA = UnitBlueprint::create([
        'name' => 'Furniture', 'workflow_set' => ['production_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $blueprintB = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => ['sales_order' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprintA->id,
        'name' => 'Furniture Plant', 'code' => 'FURN-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprintB->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Store Manager', 'slug' => 'store-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unitB->id]);

    $makeEmployee = function (string $name, OperatingUnit $unit, array $overrides = []): Employee {
        $entity = Entity::create(['name' => $name, 'entity_type' => 'individual']);

        return Employee::create(array_merge([
            'entity_id' => $entity->id,
            'operating_unit_id' => $unit->id,
            'job_title' => 'Worker',
            'pay_type' => 'hourly',
            'hire_date' => '2026-01-01',
            'status' => 'active',
        ], $overrides));
    };

    // Salaried carpenter in the furniture plant with production hours logged
    // against a real order — logs always belong to one.
    $this->salaried = $makeEmployee('Salaried Carpenter', $this->unitA, [
        'pay_type' => 'monthly', 'monthly_salary' => 3000,
    ]);

    $item = InventoryItem::create([
        'name' => 'Sofa', 'sku' => 'SOFA-1', 'item_type' => 'finished_good', 'unit_of_measure' => 'pc',
    ]);
    $product = Product::create([
        'operating_unit_id' => $this->unitA->id, 'inventory_item_id' => $item->id,
        'name' => 'Sofa', 'sku' => 'PROD-SOFA-1',
    ]);
    $bom = Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
    $order = ProductionOrder::create([
        'operating_unit_id' => $this->unitA->id, 'product_id' => $product->id, 'bom_id' => $bom->id,
        'order_number' => 'FPO-9001', 'status' => 'in_production',
    ]);

    LaborLog::create([
        'production_order_id' => $order->id,
        'employee_id' => $this->salaried->id,
        'role' => 'carpenter',
        'hours_logged' => 5,
        'hourly_rate_at_log' => 12,
        'logged_at' => '2026-08-05 10:00:00',
    ]);

    // Hourly tailor in the showroom, paid from attendance at the role rate.
    LaborRoleRate::create(['role' => 'tailor', 'hourly_rate' => 10, 'effective_from' => '2026-01-01']);
    $this->hourly = $makeEmployee('Hourly Tailor', $this->unitB, ['labor_role' => 'tailor']);

    $this->asOwner = fn () => $this->actingAs($this->owner);

    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-03',
        'entries' => [['employee_id' => $this->hourly->id, 'status' => 'present', 'hours_worked' => 8]],
    ])->assertStatus(201);
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-04',
        'entries' => [['employee_id' => $this->hourly->id, 'status' => 'present', 'hours_worked' => 8]],
    ])->assertStatus(201);
    ($this->asOwner)()->postJson('/api/v1/attendance/bulk', [
        'work_date' => '2026-08-05',
        'entries' => [['employee_id' => $this->hourly->id, 'status' => 'absent']],
    ])->assertStatus(201);

    $this->openAndCalculate = function (): array {
        $run = ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])
            ->assertStatus(201)->json();

        return ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
            ->assertStatus(200)->json();
    };
});

test('calculation builds payslips from salary, attendance and labor logs', function () {
    $run = ($this->openAndCalculate)();

    // Salaried: 3000 base + 5h × 12 logged = 3060. Hourly: 16h × 10 = 160.
    expect($run['status'])->toBe('calculated')
        ->and((float) $run['total_gross'])->toBe(3220.0)
        ->and((float) $run['total_net'])->toBe(3220.0)
        ->and($run['payslips'])->toHaveCount(2);

    $slips = collect($run['payslips'])->keyBy('employee_id');

    expect((float) $slips[$this->salaried->id]['base_pay'])->toBe(3000.0)
        ->and((float) $slips[$this->salaried->id]['labor_log_pay'])->toBe(60.0)
        ->and((float) $slips[$this->salaried->id]['gross_pay'])->toBe(3060.0)
        ->and((float) $slips[$this->hourly->id]['attendance_pay'])->toBe(160.0);
});

test('recalculating regenerates the payslips instead of duplicating them', function () {
    $run = ($this->openAndCalculate)();

    $again = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
        ->assertStatus(200)->json();

    expect($again['payslips'])->toHaveCount(2)
        ->and((float) $again['total_gross'])->toBe(3220.0);
});

test('one run per period', function () {
    ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])->assertStatus(201);

    ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PAYROLL_RUN');
});

test('deductions recalculate net and refuse to exceed gross', function () {
    $run = ($this->openAndCalculate)();
    $slip = collect($run['payslips'])->firstWhere('employee_id', $this->salaried->id);

    $updated = ($this->asOwner)()->putJson("/api/v1/payslips/{$slip['id']}/deductions", [
        'deductions' => [
            ['type' => 'social_security', 'amount' => 150],
            ['type' => 'advance', 'amount' => 200],
        ],
    ])->assertStatus(200)->json();

    // HR-07: net derived server-side.
    expect((float) $updated['net_pay'])->toBe(2710.0);

    $runFresh = PayrollRun::find($run['id']);
    expect((float) $runFresh->total_deductions)->toBe(350.0)
        ->and((float) $runFresh->total_net)->toBe(2870.0);

    ($this->asOwner)()->putJson("/api/v1/payslips/{$slip['id']}/deductions", [
        'deductions' => [['type' => 'advance', 'amount' => 99999]],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_DEDUCTIONS');
});

test('an hourly employee with payable hours but no rate blocks calculation', function () {
    // Strip the role so no rate resolves — refusal, not a silent zero.
    $this->hourly->update(['labor_role' => null, 'hourly_rate' => null]);

    $run = ($this->asOwner)()->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])->json();

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PAYROLL_CALCULATION');
});

test('the run walks the full lifecycle and posting reaches the ledger split by accrual', function () {
    $run = ($this->openAndCalculate)();
    $slip = collect($run['payslips'])->firstWhere('employee_id', $this->salaried->id);

    ($this->asOwner)()->putJson("/api/v1/payslips/{$slip['id']}/deductions", [
        'deductions' => [['type' => 'social_security', 'amount' => 100]],
    ])->assertStatus(200);

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertStatus(200);
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/mark-paid")->assertStatus(200);
    $posted = ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/post")
        ->assertStatus(200)->json();

    expect($posted['status'])->toBe('posted');

    $entry = JournalEntry::where('source_document_type', 'PayrollRun')
        ->where('source_document_id', $run['id'])->sole();
    $lines = $entry->lines()->with('account')->get();

    $unitA = $lines->where('operating_unit_id', $this->unitA->id);
    $unitB = $lines->where('operating_unit_id', $this->unitB->id);

    // Unit A: 60 settles the production accrual, 3000 is fresh wage expense,
    // 2960 cash out, 100 withheld.
    expect((float) $unitA->firstWhere(fn ($l) => $l->account->account_code === '2200')->debit)->toBe(60.0)
        ->and((float) $unitA->firstWhere(fn ($l) => $l->account->account_code === '5700')->debit)->toBe(3000.0)
        ->and((float) $unitA->firstWhere(fn ($l) => $l->account->account_code === '1200')->credit)->toBe(2960.0)
        ->and((float) $unitA->firstWhere(fn ($l) => $l->account->account_code === '2210')->credit)->toBe(100.0);

    // Unit B: pure attendance pay, no accrual, no deductions.
    expect((float) $unitB->firstWhere(fn ($l) => $l->account->account_code === '5700')->debit)->toBe(160.0)
        ->and((float) $unitB->firstWhere(fn ($l) => $l->account->account_code === '1200')->credit)->toBe(160.0)
        ->and($unitB->first(fn ($l) => $l->account->account_code === '2200'))->toBeNull();

    expect($entry->isBalanced())->toBeTrue();

    // Payslips carry the audit path back to the entry (ACC-03 / §9.7).
    expect(collect(($this->asOwner)()->getJson("/api/v1/payroll-runs/{$run['id']}/payslips")->json('data'))
        ->pluck('journal_entry_id')->unique()->sole())->toBe($entry->id);

    $tb = ($this->asOwner)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();
    expect($tb['balanced'])->toBeTrue();
});

test('the state machine refuses shortcuts', function () {
    $run = ($this->openAndCalculate)();

    // Calculated → Posted directly is not a thing (HR-06).
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/post")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // Neither is approving before submission.
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/approve")
        ->assertStatus(422);
});

test('deductions freeze once the run leaves review', function () {
    $run = ($this->openAndCalculate)();
    $slip = $run['payslips'][0];

    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    ($this->asOwner)()->putJson("/api/v1/payslips/{$slip['id']}/deductions", [
        'deductions' => [['type' => 'advance', 'amount' => 10]],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('a unit manager can neither run nor approve payroll', function () {
    // HR-10: not HR, not accounting → no payroll at all.
    $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitB->id])
        ->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'PAYROLL_FORBIDDEN');

    $run = ($this->openAndCalculate)();
    ($this->asOwner)()->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitB->id])
        ->postJson("/api/v1/payroll-runs/{$run['id']}/approve")
        ->assertStatus(403)
        ->assertJsonPath('code', 'PAYROLL_APPROVAL_FORBIDDEN');
});

test('an hr manager can run payroll but not approve it', function () {
    $hr = User::factory()->create(['must_change_password' => false]);
    $hrRole = Role::create(['name' => 'HR Manager', 'slug' => 'hr-manager']);
    UserRole::create(['user_id' => $hr->id, 'role_id' => $hrRole->id, 'operating_unit_id' => null]);

    $run = $this->actingAs($hr)->postJson('/api/v1/payroll-runs', ['period' => '2026-08'])
        ->assertStatus(201)->json();

    $this->actingAs($hr)->postJson("/api/v1/payroll-runs/{$run['id']}/calculate")->assertStatus(200);
    $this->actingAs($hr)->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertStatus(200);

    $this->actingAs($hr)->postJson("/api/v1/payroll-runs/{$run['id']}/approve")
        ->assertStatus(403)
        ->assertJsonPath('code', 'PAYROLL_APPROVAL_FORBIDDEN');
});
