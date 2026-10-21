<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Enums\PayrollRunStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\OverheadExpense;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\FiscalCalendar;
use App\Services\FixedAssetService;
use App\Services\OverheadService;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->treasury = linkedTreasury($this->unit);
});

test('an overhead expense dated 2026-09-10 posts a journal entry dated 2026-09-10', function () {
    $expense = app(OverheadService::class)->recordExpense([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'category' => 'electricity',
        'amount' => 450.0,
        'expense_date' => '2026-09-10',
        'payment_source' => 'payable',
    ]);

    $entry = JournalEntry::where('source_document_type', 'OverheadExpense')
        ->where('source_document_id', $expense->id)->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-09-10');
});

test('with September locked, recording that expense returns PERIOD_LOCKED and no OverheadExpense row exists', function () {
    app(FiscalCalendar::class)->periodFor('2026-09-15', $this->company->id)->update(['status' => FiscalPeriodStatus::Locked]);

    $response = $this->actingAs($this->owner)->postJson('/api/v1/overhead-expenses', [
        'category' => 'electricity',
        'amount' => 450.0,
        'expense_date' => '2026-09-10',
        'payment_source' => 'payable',
        'operating_unit_id' => $this->unit->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PERIOD_LOCKED');

    expect(OverheadExpense::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

test('a fixed asset acquired 2026-08-05 posts on 2026-08-05 and disposal posts on disposed_at', function () {
    $assetService = app(FixedAssetService::class);

    $asset = $assetService->acquire([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foam Cutter',
        'asset_code' => 'FA-1001',
        'acquisition_cost' => 12000.0,
        'acquisition_date' => '2026-08-05',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'salvage_value' => 0.0,
        'payment_source' => 'cash',
        'cash_account_id' => $this->treasury->id,
    ]);

    $acqEntry = JournalEntry::where('source_document_type', 'FixedAsset')
        ->where('source_document_id', $asset->id)
        ->where('description', 'like', 'Asset acquired%')
        ->sole();

    expect($acqEntry->entry_date->toDateString())->toBe('2026-08-05');

    $assetService->dispose($asset, 5000.0, '2026-10-01', $this->treasury->id);

    $dispEntry = JournalEntry::where('source_document_type', 'FixedAsset')
        ->where('source_document_id', $asset->id)
        ->where('description', 'like', 'Asset disposed%')
        ->sole();

    expect($dispEntry->entry_date->toDateString())->toBe('2026-10-01')
        ->and($asset->refresh()->disposed_at->toDateString())->toBe('2026-10-01');
});

test('depreciation for period 2026-09 is dated 2026-09-30 and for 2026-10 is dated 2026-10-20', function () {
    $assetService = app(FixedAssetService::class);

    $asset = $assetService->acquire([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foam Cutter',
        'asset_code' => 'FA-1002',
        'acquisition_cost' => 12000.0,
        'acquisition_date' => '2026-08-05',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'salvage_value' => 0.0,
        'payment_source' => 'cash',
        'cash_account_id' => $this->treasury->id,
    ]);

    $entrySep = $assetService->depreciateForPeriod($asset, '2026-09');
    $journalSep = JournalEntry::where('source_document_type', 'DepreciationEntry')
        ->where('source_document_id', $entrySep->id)->sole();
    expect($journalSep->entry_date->toDateString())->toBe('2026-09-30');

    $entryOct = $assetService->depreciateForPeriod($asset, '2026-10');
    $journalOct = JournalEntry::where('source_document_type', 'DepreciationEntry')
        ->where('source_document_id', $entryOct->id)->sole();
    expect($journalOct->entry_date->toDateString())->toBe('2026-10-20');
});

test('artisan accounting:run-depreciation with no option books period 2026-09', function () {
    $assetService = app(FixedAssetService::class);

    $asset = $assetService->acquire([
        'company_id' => $this->company->id,
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foam Cutter',
        'asset_code' => 'FA-1003',
        'acquisition_cost' => 12000.0,
        'acquisition_date' => '2026-08-05',
        'depreciation_method' => 'straight_line',
        'useful_life_years' => 5,
        'salvage_value' => 0.0,
        'payment_source' => 'cash',
        'cash_account_id' => $this->treasury->id,
    ]);

    $this->artisan('accounting:run-depreciation')
        ->expectsOutputToContain('Depreciation 2026-09: 1 posted, 0 skipped, 0 failed.')
        ->assertExitCode(0);

    $depEntry = $asset->depreciationEntries()->where('period', '2026-09')->sole();
    $journal = JournalEntry::where('source_document_type', 'DepreciationEntry')
        ->where('source_document_id', $depEntry->id)->sole();
    expect($journal->entry_date->toDateString())->toBe('2026-09-30');
});

test('posting payroll for period 2026-09 creates two entries with expected dates and accounts', function () {
    $entity = Entity::create(['name' => 'Ali Hassan', 'entity_type' => 'individual', 'company_id' => $this->company->id]);
    $employee = Employee::create([
        'company_id' => $this->company->id,
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Worker',
        'pay_type' => 'monthly',
        'monthly_salary' => 3000.0,
        'status' => 'active',
        'hire_date' => '2026-01-01',
    ]);

    $payrollService = app(PayrollService::class);
    $run = $payrollService->open('2026-09', $this->owner->id);
    $run = $payrollService->calculate($run);

    $slip = $run->payslips()->sole();
    $payrollService->setDeductions($slip, [['type' => 'social_security', 'amount' => 100.0]]);

    $run = $payrollService->transition($run, PayrollRunStatus::PendingApproval);
    $run = $payrollService->transition($run, PayrollRunStatus::Approved, $this->owner->id);
    $run = $payrollService->transition($run, PayrollRunStatus::Paid);
    $run = $payrollService->transition($run, PayrollRunStatus::Posted, $this->owner->id, $this->treasury->id);

    $accrualEntry = JournalEntry::where('source_document_type', 'PayrollRun')
        ->where('source_document_id', $run->id)
        ->where('description', 'Payroll 2026-09 accrued')
        ->sole();

    $paymentEntry = JournalEntry::where('source_document_type', 'PayrollRun')
        ->where('source_document_id', $run->id)
        ->where('description', 'Payroll 2026-09 paid')
        ->sole();

    expect($accrualEntry->entry_date->toDateString())->toBe('2026-09-30')
        ->and($paymentEntry->entry_date->toDateString())->toBe('2026-10-20');

    $accrualLines = $accrualEntry->lines()->with('account')->get();
    expect((float) $accrualLines->firstWhere(fn ($l) => $l->account->account_code === '57')->debit)->toBe(3000.0)
        ->and((float) $accrualLines->firstWhere(fn ($l) => $l->account->account_code === '226')->credit)->toBe(2900.0)
        ->and((float) $accrualLines->firstWhere(fn ($l) => $l->account->account_code === '225')->credit)->toBe(100.0);

    $paymentLines = $paymentEntry->lines()->with('account')->get();
    expect((float) $paymentLines->firstWhere(fn ($l) => $l->account->account_code === '226')->debit)->toBe(2900.0)
        ->and((float) $paymentLines->firstWhere(fn ($l) => $l->account->account_code === '121101')->credit)->toBe(2900.0);

    // Account 226 nets to zero
    $net226Debit = (float) $paymentLines->where('account.account_code', '226')->sum('debit');
    $net226Credit = (float) $accrualLines->where('account.account_code', '226')->sum('credit');
    expect($net226Debit)->toBe($net226Credit);

    // Payslips link to the accrual
    expect($slip->refresh()->journal_entry_id)->toBe($accrualEntry->id);

    // Run's payment_journal_entry_id is the payment
    expect($run->refresh()->payment_journal_entry_id)->toBe($paymentEntry->id);
});

test('with September locked, posting 2026-09 payroll is refused with PERIOD_LOCKED and run stays approved', function () {
    $entity = Entity::create(['name' => 'Ali Hassan', 'entity_type' => 'individual', 'company_id' => $this->company->id]);
    Employee::create([
        'company_id' => $this->company->id,
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Worker',
        'pay_type' => 'monthly',
        'monthly_salary' => 3000.0,
        'status' => 'active',
        'hire_date' => '2026-01-01',
    ]);

    $payrollService = app(PayrollService::class);
    $run = $payrollService->open('2026-09', $this->owner->id);
    $run = $payrollService->calculate($run);
    $run = $payrollService->transition($run, PayrollRunStatus::PendingApproval);
    $run = $payrollService->transition($run, PayrollRunStatus::Approved, $this->owner->id);
    $run = $payrollService->transition($run, PayrollRunStatus::Paid);

    app(FiscalCalendar::class)->periodFor('2026-09-15', $this->company->id)->update(['status' => FiscalPeriodStatus::Locked]);

    $response = $this->actingAs($this->owner)->postJson("/api/v1/payroll-runs/{$run->id}/post", [
        'cash_account_id' => $this->treasury->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PERIOD_LOCKED');

    expect($run->refresh()->status)->toBe(PayrollRunStatus::Paid)
        ->and($run->payment_journal_entry_id)->toBeNull()
        ->and(JournalEntry::where('source_document_type', 'PayrollRun')->count())->toBe(0);
});
