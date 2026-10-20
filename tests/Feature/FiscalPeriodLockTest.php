<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\FiscalCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $makeUser = function (string $slug): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

        return $user;
    };
    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');

    $this->accounting = app(AccountingService::class);
    $this->post = fn (string $date) => $this->accounting->postJournal("Entry {$date}", [
        ['account_code' => '54', 'debit' => 10.0],
        ['account_code' => '223', 'credit' => 10.0],
    ], entryDate: $date);

    $this->period = fn (int $year, int $month) => FiscalPeriod::query()
        ->where('calendar_year', $year)->where('calendar_month', $month)->sole();
    $this->lock = fn (FiscalPeriod $p, User $user, array $body = []) => $this->actingAs($user)
        ->postJson("/api/v1/fiscal-periods/{$p->id}/lock", $body);
});

test('the first posting in a year creates the year and its twelve months', function () {
    ($this->post)('2026-09-15');

    $year = FiscalYear::sole();
    expect($year->code)->toBe('FY2026')
        ->and($year->start_date->toDateString())->toBe('2026-01-01')
        ->and($year->end_date->toDateString())->toBe('2026-12-31')
        ->and($year->periods)->toHaveCount(12);

    $index = $this->actingAs($this->owner)->getJson('/api/v1/fiscal-years')->assertOk()->json();
    $september = collect($index['data'][0]['periods'])->firstWhere('label', '2026-09');
    expect($september['entry_count'])->toBe(1)
        ->and($index['fiscal_year_start_month'])->toBe(1);
});

test('a locked month refuses manual, automatic and reversal postings (ACC-11)', function () {
    $september = ($this->post)('2026-09-15');
    ($this->lock)(($this->period)(2026, 9), $this->accountant, ['include_earlier' => true])->assertOk();

    // Manual entry dated in the locked month.
    $this->actingAs($this->accountant)->postJson('/api/v1/journal-entries', [
        'description' => 'Late correction', 'entry_date' => '2026-09-20',
        'lines' => [['account_code' => '54', 'debit' => 5], ['account_code' => '223', 'credit' => 5]],
    ])->assertStatus(422)->assertJsonPath('code', 'PERIOD_LOCKED')->assertJsonPath('period', '2026-09');

    // Automatic posting from a business event (the source type is set, not manual).
    expect(fn () => $this->accounting->postJournal('Depreciation 2026-09', [
        ['account_code' => '54', 'debit' => 30.0],
        ['account_code' => '223', 'credit' => 30.0],
    ], 'FixedAsset', '01a00000-0000-7000-8000-000000000009', entryDate: '2026-09-30'))->toThrow(LedgerRuleException::class, 'locked');

    // A reversal dated in the locked month is refused; dated today it posts.
    $this->actingAs($this->owner)->postJson("/api/v1/journal-entries/{$september->id}/reverse", ['reason' => 'x', 'entry_date' => '2026-09-30'])
        ->assertStatus(422)->assertJsonPath('code', 'PERIOD_LOCKED');
    $this->actingAs($this->owner)->postJson("/api/v1/journal-entries/{$september->id}/reverse", ['reason' => 'x'])
        ->assertCreated();

    expect(JournalEntry::count())->toBe(2);

    // The open current month still takes postings.
    expect(($this->post)('2026-10-20')->id)->not->toBeNull();
});

test('only ended months lock, in order unless earlier months are included', function () {
    ($this->post)('2026-08-10');

    ($this->lock)(($this->period)(2026, 10), $this->accountant)
        ->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_ENDED');

    ($this->lock)(($this->period)(2026, 9), $this->accountant)
        ->assertStatus(422)->assertJsonPath('code', 'PERIOD_LOCK_OUT_OF_ORDER')
        ->assertJsonPath('open_periods.0', '2026-01');

    ($this->lock)(($this->period)(2026, 9), $this->accountant, ['include_earlier' => true])
        ->assertOk()->assertJsonCount(9, 'locked');

    ($this->lock)(($this->period)(2026, 9), $this->accountant)
        ->assertStatus(422)->assertJsonPath('code', 'PERIOD_ALREADY_LOCKED');

    expect(FiscalPeriod::where('status', 'locked')->count())->toBe(9);
});

test('only the owner reopens a month, with a reason that is audit-logged', function () {
    ($this->post)('2026-09-15');
    $september = ($this->period)(2026, 9);
    ($this->lock)($september, $this->accountant, ['include_earlier' => true])->assertOk();

    $this->actingAs($this->accountant)->postJson("/api/v1/fiscal-periods/{$september->id}/reopen", ['reason' => 'Missing invoice'])
        ->assertForbidden();
    $this->actingAs($this->owner)->postJson("/api/v1/fiscal-periods/{$september->id}/reopen", [])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    $this->actingAs($this->owner)->postJson("/api/v1/fiscal-periods/{$september->id}/reopen", ['reason' => 'Missing invoice'])
        ->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('reopen_reason', 'Missing invoice');

    // Only that month reopens.
    expect(($this->period)(2026, 8)->status)->toBe(FiscalPeriodStatus::Locked)
        ->and(AuditLog::where('table_name', 'fiscal_periods')->where('record_id', $september->id)->where('action', 'updated')->count())->toBeGreaterThanOrEqual(2);

    expect(($this->post)('2026-09-28')->id)->not->toBeNull();
});

test('the fiscal start month can change only while nothing is locked', function () {
    ($this->post)('2026-09-15');

    $this->actingAs($this->accountant)->putJson('/api/v1/fiscal-settings', ['fiscal_year_start_month' => 7])->assertForbidden();
    $this->actingAs($this->owner)->putJson('/api/v1/fiscal-settings', ['fiscal_year_start_month' => 7])
        ->assertOk()->assertJsonPath('fiscal_year_start_month', 7);

    ($this->post)('2026-09-16');
    $year = FiscalYear::sole();
    expect($year->code)->toBe('FY2026-2027')
        ->and($year->start_date->toDateString())->toBe('2026-07-01')
        ->and($year->end_date->toDateString())->toBe('2027-06-30');

    ($this->lock)(($this->period)(2026, 9), $this->accountant, ['include_earlier' => true])->assertOk();
    $this->actingAs($this->owner)->putJson('/api/v1/fiscal-settings', ['fiscal_year_start_month' => 1])
        ->assertStatus(422)->assertJsonPath('code', 'FISCAL_CALENDAR_IN_USE');
});

test('fiscal years can start mid-year', function () {
    $bounds = FiscalCalendar::yearBounds(CarbonImmutable::parse('2026-03-10'), 7);

    expect($bounds['start']->toDateString())->toBe('2025-07-01')
        ->and($bounds['end']->toDateString())->toBe('2026-06-30');
});

test('dates more than a year ahead are refused', function () {
    expect(fn () => ($this->post)('2028-01-01'))->toThrow(LedgerRuleException::class, 'more than a year ahead');
});

test('just after midnight Tripoli time an entry is dated on the Tripoli day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC'));

    $entry = $this->accounting->postJournal('Night sale', [
        ['account_code' => '54', 'debit' => 1.0],
        ['account_code' => '223', 'credit' => 1.0],
    ]);

    expect($entry->entry_date->toDateString())->toBe('2026-11-01');
});
