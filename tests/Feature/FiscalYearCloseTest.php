<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\FiscalPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalEntryType;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Services\FiscalCalendar;
use App\Services\FiscalPeriodService;
use App\Services\FiscalYearService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'SHOW-01', 'unit_type' => 'showroom', 'status' => 'active',
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
    $this->fiscalYears = app(FiscalYearService::class);
    $this->fiscalPeriods = app(FiscalPeriodService::class);
    $this->calendar = app(FiscalCalendar::class);

    // Seed activity in 2026:
    $this->year2026 = $this->fiscalPeriods->ensureYear($this->company->id, 2026);

    // Revenue in unit A:
    $this->accounting->postJournal('Sale Unit A', [
        ['account_code' => '121101', 'debit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
        ['account_code' => '40201', 'credit' => 1000.0, 'operating_unit_id' => $this->unitA->id],
    ], entryDate: '2026-03-15');

    // Expense in unit B:
    $this->accounting->postJournal('Expense Unit B', [
        ['account_code' => '54', 'debit' => 400.0, 'operating_unit_id' => $this->unitB->id],
        ['account_code' => '223', 'credit' => 400.0, 'operating_unit_id' => $this->unitB->id],
    ], entryDate: '2026-06-20');

    // Unallocated P&L line (no unit):
    $this->accounting->postJournal('Company FX Loss', [
        ['account_code' => '53', 'debit' => 50.0],
        ['account_code' => '223', 'credit' => 50.0],
    ], entryDate: '2026-08-10');

    // Lock all 12 months directly:
    FiscalPeriod::query()->where('fiscal_year_id', $this->year2026->id)->update([
        'status' => FiscalPeriodStatus::Locked,
        'locked_at' => now(),
        'locked_by_user_id' => $this->accountant->id,
    ]);
});

test('preview lists opposite lines plus one 33 line per unit with correct net income and empty blockers', function () {
    $preview = $this->fiscalYears->preview($this->year2026);

    expect($preview['net_income'])->toBe(550.0)
        ->and($preview['total_debit'])->toBe(1450.0)
        ->and($preview['total_credit'])->toBe(1450.0)
        ->and($preview['blockers'])->toBeEmpty();

    $lines = collect($preview['lines']);

    // Opposite lines
    $revLine = $lines->firstWhere('account_code', '40201');
    expect($revLine['debit'])->toBe(1000.0)
        ->and($revLine['credit'])->toBe(0.0)
        ->and($revLine['operating_unit_id'])->toBe($this->unitA->id);

    $expBLine = $lines->firstWhere('account_code', '54');
    expect($expBLine['debit'])->toBe(0.0)
        ->and($expBLine['credit'])->toBe(400.0)
        ->and($expBLine['operating_unit_id'])->toBe($this->unitB->id);

    $expUnallocLine = $lines->firstWhere('account_code', '53');
    expect($expUnallocLine['debit'])->toBe(0.0)
        ->and($expUnallocLine['credit'])->toBe(50.0)
        ->and($expUnallocLine['operating_unit_id'])->toBeNull();

    // 33 lines
    $retainedLines = $lines->where('account_code', '33');
    expect($retainedLines)->toHaveCount(3);

    $unitA33 = $retainedLines->firstWhere('operating_unit_id', $this->unitA->id);
    expect($unitA33['credit'])->toBe(1000.0)
        ->and($unitA33['debit'])->toBe(0.0);

    $unitB33 = $retainedLines->firstWhere('operating_unit_id', $this->unitB->id);
    expect($unitB33['debit'])->toBe(400.0)
        ->and($unitB33['credit'])->toBe(0.0);

    $unalloc33 = $retainedLines->firstWhere('operating_unit_id', null);
    expect($unalloc33['debit'])->toBe(50.0)
        ->and($unalloc33['credit'])->toBe(0.0);
});

test('close posts ONE balanced closing entry dated 2026-12-31, zeroes P&L accounts, and sets 33 per unit', function () {
    $closed = $this->fiscalYears->close($this->year2026, $this->accountant);

    expect($closed->status)->toBe(FiscalYearStatus::Closed)
        ->and($closed->closing_entry_id)->not->toBeNull();

    $closing = JournalEntry::with('lines')->findOrFail($closed->closing_entry_id);
    expect($closing->entry_type)->toBe(JournalEntryType::Closing)
        ->and($closing->entry_date->toDateString())->toBe('2026-12-31')
        ->and($closing->isBalanced())->toBeTrue();

    // P&L accounts net to zero at 2026-12-31
    $pnlLines = JournalLine::query()
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->whereIn('accounts.account_code', ['40201', '54', '53'])
        ->whereDate('journal_entries.entry_date', '<=', '2026-12-31')
        ->selectRaw('accounts.account_code, SUM(journal_lines.debit) as deb, SUM(journal_lines.credit) as cred')
        ->groupBy('accounts.account_code')
        ->get();

    foreach ($pnlLines as $pnl) {
        expect(round((float) $pnl->deb - (float) $pnl->cred, 4))->toBe(0.0);
    }

    // 33 holds each unit's net
    $account33Id = Account::where('account_code', '33')->value('id');
    $retainedLines = $closing->lines->where('account_id', $account33Id);

    expect((float) $retainedLines->firstWhere('operating_unit_id', $this->unitA->id)->credit)->toBe(1000.0)
        ->and((float) $retainedLines->firstWhere('operating_unit_id', $this->unitB->id)->debit)->toBe(400.0)
        ->and((float) $retainedLines->firstWhere('operating_unit_id', null)->debit)->toBe(50.0);
});

test('balance sheet is balanced company-wide and per-unit before and after close across dates, preserving total equity', function () {
    $reportService = app(FinancialReportService::class);

    $bsBeforeJune = $reportService->balanceSheet('2026-06-30');
    $bsBeforeDec = $reportService->balanceSheet('2026-12-31');
    $bsBeforeFeb = $reportService->balanceSheet('2027-02-10');

    expect($bsBeforeJune['balanced'])->toBeTrue()
        ->and($bsBeforeDec['balanced'])->toBeTrue()
        ->and($bsBeforeFeb['balanced'])->toBeTrue();

    $equityBefore = $bsBeforeDec['equity']['total'];

    $this->fiscalYears->close($this->year2026, $this->accountant);

    $bsAfterJune = $reportService->balanceSheet('2026-06-30');
    $bsAfterDec = $reportService->balanceSheet('2026-12-31');
    $bsAfterFeb = $reportService->balanceSheet('2027-02-10');

    expect($bsAfterJune['balanced'])->toBeTrue()
        ->and($bsAfterDec['balanced'])->toBeTrue()
        ->and($bsAfterFeb['balanced'])->toBeTrue()
        ->and($bsAfterDec['equity']['total'])->toBe($equityBefore);

    // Per unit
    expect($reportService->balanceSheet('2026-12-31', $this->unitA->id)['balanced'])->toBeTrue()
        ->and($reportService->balanceSheet('2026-12-31', $this->unitB->id)['balanced'])->toBeTrue();
});

test('income statement and unit profitability for 2026 are identical before and after close', function () {
    $reportService = app(FinancialReportService::class);

    $isBefore = $reportService->incomeStatement('2026-01-01', '2026-12-31');
    $upBefore = $reportService->unitProfitability('2026-01-01', '2026-12-31');

    $this->fiscalYears->close($this->year2026, $this->accountant);

    $isAfter = $reportService->incomeStatement('2026-01-01', '2026-12-31');
    $upAfter = $reportService->unitProfitability('2026-01-01', '2026-12-31');

    expect($isAfter['revenue']['total'])->toBe($isBefore['revenue']['total'])
        ->and($isAfter['expenses']['total'])->toBe($isBefore['expenses']['total'])
        ->and($isAfter['net_income'])->toBe($isBefore['net_income'])
        ->and($upAfter['total_net'])->toBe($upBefore['total_net'])
        ->and($upAfter['rows'])->toEqual($upBefore['rows']);
});

test('refusals are raised in proper order: YEAR_NOT_ENDED, YEAR_PERIODS_NOT_LOCKED, PREVIOUS_YEAR_OPEN, RETAINED_EARNINGS_NOT_POSTABLE, YEAR_ALREADY_CLOSED, LATER_YEAR_CLOSED', function () {
    // 1. YEAR_NOT_ENDED
    $this->travelTo(CarbonImmutable::parse('2026-11-15', 'Africa/Tripoli'));
    try {
        $this->fiscalYears->close($this->year2026, $this->accountant);
        $this->fail('Expected YEAR_NOT_ENDED');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('YEAR_NOT_ENDED');
    }

    // Travel back to 2027
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00', 'Africa/Tripoli'));

    // 2. YEAR_PERIODS_NOT_LOCKED
    $december = FiscalPeriod::where('fiscal_year_id', $this->year2026->id)->where('calendar_month', 12)->first();
    $december->update(['status' => FiscalPeriodStatus::Open]);
    try {
        $this->fiscalYears->close($this->year2026, $this->accountant);
        $this->fail('Expected YEAR_PERIODS_NOT_LOCKED');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('YEAR_PERIODS_NOT_LOCKED')
            ->and($e->context['open_periods'])->toContain('2026-12');
    }
    $december->update(['status' => FiscalPeriodStatus::Locked]);

    // 3. PREVIOUS_YEAR_OPEN
    $year2025 = $this->fiscalPeriods->ensureYear($this->company->id, 2025);
    try {
        $this->fiscalYears->close($this->year2026, $this->accountant);
        $this->fail('Expected PREVIOUS_YEAR_OPEN');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('PREVIOUS_YEAR_OPEN');
    }
    // Lock 2025 periods and close 2025
    FiscalPeriod::where('fiscal_year_id', $year2025->id)->update(['status' => FiscalPeriodStatus::Locked]);
    $this->fiscalYears->close($year2025, $this->accountant);

    // 4. RETAINED_EARNINGS_NOT_POSTABLE
    $account33 = Account::where('account_code', '33')->first();
    $account33->update(['status' => AccountStatus::Frozen]);
    try {
        $this->fiscalYears->close($this->year2026, $this->accountant);
        $this->fail('Expected RETAINED_EARNINGS_NOT_POSTABLE');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('RETAINED_EARNINGS_NOT_POSTABLE');
    }
    $account33->update(['status' => AccountStatus::Active]);

    // Close 2026 successfully
    $this->fiscalYears->close($this->year2026, $this->accountant);

    // 5. YEAR_ALREADY_CLOSED
    try {
        $this->fiscalYears->close($this->year2026, $this->accountant);
        $this->fail('Expected YEAR_ALREADY_CLOSED');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('YEAR_ALREADY_CLOSED');
    }

    // 6. LATER_YEAR_CLOSED on reopen
    $year2027 = $this->fiscalPeriods->ensureYear($this->company->id, 2027);
    $this->travelTo(CarbonImmutable::parse('2028-02-10 10:00:00', 'Africa/Tripoli'));
    FiscalPeriod::where('fiscal_year_id', $year2027->id)->update(['status' => FiscalPeriodStatus::Locked]);
    $this->fiscalYears->close($year2027, $this->accountant);

    try {
        $this->fiscalYears->reopen($this->year2026, $this->owner, 'Need to adjust 2026');
        $this->fail('Expected LATER_YEAR_CLOSED');
    } catch (LedgerRuleException $e) {
        expect($e->errorCode)->toBe('LATER_YEAR_CLOSED');
    }
});

test('postings into closed year are refused with YEAR_CLOSED', function () {
    $this->fiscalYears->close($this->year2026, $this->accountant);

    expect(fn () => $this->accounting->postJournal('Late 2026 sale', [
        ['account_code' => '121101', 'debit' => 100.0],
        ['account_code' => '40201', 'credit' => 100.0],
    ], entryDate: '2026-05-10'))->toThrow(LedgerRuleException::class, 'closed');
});

test('reopen reverses closing entry, opens year, keeps months locked, and allows closing again', function () {
    $closed = $this->fiscalYears->close($this->year2026, $this->accountant);
    $closingEntryId = $closed->closing_entry_id;

    $reopened = $this->fiscalYears->reopen($closed, $this->owner, 'Audit adjustments required');

    expect($reopened->status)->toBe(FiscalYearStatus::Open)
        ->and($reopened->reopened_at)->not->toBeNull()
        ->and($reopened->reopened_by_user_id)->toBe($this->owner->id)
        ->and($reopened->reopen_reason)->toBe('Audit adjustments required')
        ->and($reopened->closing_entry_id)->toBeNull();

    // Months remain locked
    expect($reopened->periods->every(fn ($p) => $p->status === FiscalPeriodStatus::Locked))->toBeTrue();

    // Reversal entry exists
    $reversal = JournalEntry::where('reversal_of_id', $closingEntryId)->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->entry_type)->toBe(JournalEntryType::Reversal)
        ->and($reversal->reversal_reason)->toBe('Audit adjustments required');

    // Closing again works and creates a new closing entry
    $reclosed = $this->fiscalYears->close($reopened, $this->accountant);
    expect($reclosed->status)->toBe(FiscalYearStatus::Closed)
        ->and($reclosed->closing_entry_id)->not->toBeNull()
        ->and($reclosed->closing_entry_id)->not->toBe($closingEntryId);
});

test('reopen by accounting manager is 403 while close works via API', function () {
    // Reopen by accounting manager is 403
    $this->actingAs($this->accountant)
        ->postJson("/api/v1/fiscal-years/{$this->year2026->id}/reopen", ['reason' => 'test'])
        ->assertStatus(403);

    // Close by accounting manager works via API
    $response = $this->actingAs($this->accountant)
        ->postJson("/api/v1/fiscal-years/{$this->year2026->id}/close")
        ->assertOk()
        ->json();

    expect($response['status'])->toBe('closed')
        ->and($response['closing_entry_id'])->not->toBeNull();

    // Owner can reopen via API
    $this->actingAs($this->owner)
        ->postJson("/api/v1/fiscal-years/{$this->year2026->id}/reopen", ['reason' => 'Owner reopening'])
        ->assertOk()
        ->assertJsonPath('status', 'open');
});

test('a year with no P&L activity closes without an entry', function () {
    $year2028 = $this->fiscalPeriods->ensureYear($this->company->id, 2028);
    $this->travelTo(CarbonImmutable::parse('2029-01-15 10:00:00', 'Africa/Tripoli'));

    // Post an asset/liability entry only (no revenue/expense)
    $this->accounting->postJournal('Cash transfer', [
        ['account_code' => '121101', 'debit' => 500.0],
        ['account_code' => '121102', 'credit' => 500.0],
    ], entryDate: '2028-04-10');

    FiscalPeriod::where('fiscal_year_id', $year2028->id)->update(['status' => FiscalPeriodStatus::Locked]);

    // Close 2026 first so earlier year is not open
    $this->fiscalYears->close($this->year2026, $this->accountant);

    $closed2028 = $this->fiscalYears->close($year2028, $this->accountant);
    expect($closed2028->status)->toBe(FiscalYearStatus::Closed)
        ->and($closed2028->closing_entry_id)->toBeNull();
});

test('user-level reverse on closing entry returns CLOSING_NOT_REVERSIBLE', function () {
    $closed = $this->fiscalYears->close($this->year2026, $this->accountant);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/journal-entries/{$closed->closing_entry_id}/reverse", ['reason' => 'User trying direct reversal'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CLOSING_NOT_REVERSIBLE');
});

test('account 401 is type asset with parent 13 after seeding', function () {
    $account401 = Account::where('account_code', '401')->firstOrFail();
    $parent13 = Account::where('account_code', '13')->firstOrFail();

    expect($account401->type)->toBe('asset')
        ->and($account401->parent_account_id)->toBe($parent13->id);
});

test('fiscal-years index includes closing_entry with id and reference when closed', function () {
    $closed = $this->fiscalYears->close($this->year2026, $this->accountant);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-years')
        ->assertOk()
        ->json();

    $yearData = collect($response['data'])->firstWhere('id', $this->year2026->id);
    expect($yearData['closing_entry'])->not->toBeNull()
        ->and($yearData['closing_entry']['id'])->toBe($closed->closing_entry_id)
        ->and($yearData['closing_entry']['reference'])->not->toBeEmpty();
});
