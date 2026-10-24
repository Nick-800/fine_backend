<?php

declare(strict_types=1);

use App\Enums\BankReconciliationStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use App\Services\BankReconciliationService;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:00', 'Africa/Tripoli'));

    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant',
        'code' => 'FOAM-01',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    $makeUser = function (string $slug, ?string $unitId = null): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        RolePermissions::grantDefaults($role);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner');
    $this->accountant = $makeUser('accounting-manager');
    $this->treasuryOfficer = $makeUser('treasury-officer', $this->unit->id);
    $this->storeManager = $makeUser('store-manager', $this->unit->id);

    // Cash (non-bank) treasury
    $this->cashTreasury = linkedTreasury($this->unit, '121101', 'cash');

    // Create a new 1212xx leaf under 1212
    $bankParent = Account::where('account_code', '1212')->firstOrFail();
    $this->bankAccount = Account::create([
        'chart_of_accounts_id' => $bankParent->chart_of_accounts_id,
        'account_code' => '121201',
        'name' => 'حساب مصرف التجارة - الرئيسي',
        'type' => 'asset',
        'parent_account_id' => $bankParent->id,
        'currency' => 'LYD',
    ]);

    $this->bankTreasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'خزينة مصرف التجارة',
        'kind' => CashAccount::KIND_BANK,
        'account_id' => $this->bankAccount->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $this->accounting = app(AccountingService::class);
    $this->recService = app(BankReconciliationService::class);

    $this->actAs = fn (User $user) => $this->actingAs($user)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);
});

test('bank reconciliation lifecycle: postings, candidates filtering, summary updates, balanced finish, and opening chain', function (): void {
    // 1. Postings: deposit, payment, and later line
    $depositEntry = $this->accounting->postJournal('Client deposit', [
        ['account_code' => '121201', 'debit' => 1000.0],
        ['account_code' => '31', 'credit' => 1000.0],
    ], entryDate: '2026-10-10');

    $paymentEntry = $this->accounting->postJournal('Bank charge', [
        ['account_code' => '31', 'debit' => 200.0],
        ['account_code' => '121201', 'credit' => 200.0],
    ], entryDate: '2026-10-12');

    $laterEntry = $this->accounting->postJournal('Subsequent deposit', [
        ['account_code' => '121201', 'debit' => 300.0],
        ['account_code' => '31', 'credit' => 300.0],
    ], entryDate: '2026-10-18');

    $depositLine = $depositEntry->lines()->where('account_id', $this->bankAccount->id)->sole();
    $paymentLine = $paymentEntry->lines()->where('account_id', $this->bankAccount->id)->sole();
    $laterLine = $laterEntry->lines()->where('account_id', $this->bankAccount->id)->sole();

    // 2. Start reconciliation on 2026-10-15
    $startResponse = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 800.0,
        'notes' => 'October mid-month statement',
    ])->assertStatus(201);

    $recId = $startResponse->json('data.reconciliation.id');
    expect($recId)->not()->toBeNull();

    $startResponse->assertJsonPath('data.reconciliation.status', 'draft')
        ->assertJsonPath('data.reconciliation.status_label', 'مسودة')
        ->assertJsonPath('data.reconciliation.statement_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.book_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.opening_cleared_balance', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.cleared_debit', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.cleared_credit', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.cleared_balance', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.statement_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.uncleared_deposits', fn ($v): bool => (float) $v === 1000.0)
        ->assertJsonPath('data.summary.uncleared_payments', fn ($v): bool => (float) $v === 200.0);

    // Candidates exclude the later line
    $candidateLines = $startResponse->json('data.lines');
    expect($candidateLines)->toHaveCount(2);
    $candidateIds = array_column($candidateLines, 'id');
    expect($candidateIds)->toContain($depositLine->id)
        ->and($candidateIds)->toContain($paymentLine->id)
        ->and($candidateIds)->not()->toContain($laterLine->id);

    // 3. Tick deposit line -> summary moves
    $tickDepositResponse = ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/lines", [
        'line_ids' => [$depositLine->id],
        'cleared' => true,
    ])->assertOk();

    $tickDepositResponse->assertJsonPath('data.summary.cleared_debit', fn ($v): bool => (float) $v === 1000.0)
        ->assertJsonPath('data.summary.cleared_credit', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.cleared_balance', fn ($v): bool => (float) $v === 1000.0)
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === -200.0)
        ->assertJsonPath('data.summary.uncleared_deposits', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.uncleared_payments', fn ($v): bool => (float) $v === 200.0);

    // 4. Finish refused while difference != 0
    ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/finish")
        ->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_NOT_BALANCED')
        ->assertJsonPath('difference', fn ($v): bool => (float) $v === -200.0);

    // Tick payment line -> difference becomes 0
    $tickPaymentResponse = ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/lines", [
        'line_ids' => [$paymentLine->id],
        'cleared' => true,
    ])->assertOk();

    $tickPaymentResponse->assertJsonPath('data.summary.cleared_debit', fn ($v): bool => (float) $v === 1000.0)
        ->assertJsonPath('data.summary.cleared_credit', fn ($v): bool => (float) $v === 200.0)
        ->assertJsonPath('data.summary.cleared_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.uncleared_deposits', fn ($v): bool => (float) $v === 0.0)
        ->assertJsonPath('data.summary.uncleared_payments', fn ($v): bool => (float) $v === 0.0);

    // Finish allowed at difference = 0
    $finishResponse = ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/finish")
        ->assertOk();

    $finishResponse->assertJsonPath('data.reconciliation.status', 'finished')
        ->assertJsonPath('data.reconciliation.status_label', 'منتهية')
        ->assertJsonPath('data.reconciliation.finished_by.id', $this->treasuryOfficer->id);

    expect(BankReconciliation::find($recId)->status)->toBe(BankReconciliationStatus::Finished);

    // 5. Next reconciliation: finished lines are NOT candidates, and opening_cleared_balance chains
    $newDeposit = $this->accounting->postJournal('Next period deposit', [
        ['account_code' => '121201', 'debit' => 500.0],
        ['account_code' => '31', 'credit' => 500.0],
    ], entryDate: '2026-10-22');
    $newDepositLine = $newDeposit->lines()->where('account_id', $this->bankAccount->id)->sole();

    // 800 previous statement balance + 300 later + 500 new = 1600.0
    $secondStartResponse = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-23',
        'statement_balance' => 1600.0,
    ])->assertStatus(201);

    $secondCandidates = $secondStartResponse->json('data.lines');
    $secondCandidateIds = array_column($secondCandidates, 'id');

    expect($secondCandidateIds)->not()->toContain($depositLine->id)
        ->and($secondCandidateIds)->not()->toContain($paymentLine->id)
        ->and($secondCandidateIds)->toContain($laterLine->id)
        ->and($secondCandidateIds)->toContain($newDepositLine->id);

    $secondStartResponse->assertJsonPath('data.summary.opening_cleared_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.cleared_balance', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.statement_balance', fn ($v): bool => (float) $v === 1600.0)
        ->assertJsonPath('data.summary.difference', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.uncleared_deposits', fn ($v): bool => (float) $v === 800.0)
        ->assertJsonPath('data.summary.uncleared_payments', fn ($v): bool => (float) $v === 0.0);
});

test('cash non-bank treasury is refused', function (): void {
    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->cashTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 500.0,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_NOT_BANK');
});

test('second draft for the same bank treasury is refused', function (): void {
    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 500.0,
    ])->assertStatus(201);

    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-16',
        'statement_balance' => 600.0,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_DRAFT_EXISTS');
});

test('statement date before or equal to last finished statement date is refused', function (): void {
    $rec = $this->recService->start(
        $this->bankTreasury,
        '2026-10-15',
        0.0,
        $this->treasuryOfficer,
    );
    $this->recService->finish($rec, $this->treasuryOfficer);

    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 500.0,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_DATE_BEFORE_LAST');

    ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-14',
        'statement_balance' => 500.0,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_DATE_BEFORE_LAST');
});

test('line from soft-deleted entry is not a candidate', function (): void {
    $keptEntry = $this->accounting->postJournal('Kept deposit', [
        ['account_code' => '121201', 'debit' => 100.0],
        ['account_code' => '31', 'credit' => 100.0],
    ], entryDate: '2026-10-10');

    $deletedEntry = $this->accounting->postJournal('Deleted deposit', [
        ['account_code' => '121201', 'debit' => 50.0],
        ['account_code' => '31', 'credit' => 50.0],
    ], entryDate: '2026-10-11');

    DB::table('journal_entries')->where('id', $deletedEntry->id)->update(['deleted_at' => now()]);

    $keptLine = $keptEntry->lines()->where('account_id', $this->bankAccount->id)->sole();
    $deletedLine = $deletedEntry->lines()->where('account_id', $this->bankAccount->id)->sole();

    $response = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 100.0,
    ])->assertStatus(201);

    $candidateIds = array_column($response->json('data.lines'), 'id');
    expect($candidateIds)->toContain($keptLine->id)
        ->and($candidateIds)->not()->toContain($deletedLine->id);
});

test('line not on this treasury account is refused LINE_NOT_RECONCILABLE', function (): void {
    $cashEntry = $this->accounting->postJournal('Cash deposit', [
        ['account_code' => '121101', 'debit' => 75.0],
        ['account_code' => '31', 'credit' => 75.0],
    ], entryDate: '2026-10-10');
    $cashLine = $cashEntry->lines()->firstWhere('debit', 75.0);

    $startResponse = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 0.0,
    ])->assertStatus(201);

    $recId = $startResponse->json('data.reconciliation.id');

    ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/lines", [
        'line_ids' => [$cashLine->id],
        'cleared' => true,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'LINE_NOT_RECONCILABLE');
});

test('discard deletes draft reconciliation and lines, refused on finished', function (): void {
    $entry = $this->accounting->postJournal('Deposit', [
        ['account_code' => '121201', 'debit' => 100.0],
        ['account_code' => '31', 'credit' => 100.0],
    ], entryDate: '2026-10-10');
    $line = $entry->lines()->where('account_id', $this->bankAccount->id)->sole();

    $startResponse = ($this->actAs)($this->treasuryOfficer)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-15',
        'statement_balance' => 100.0,
    ])->assertStatus(201);

    $recId = $startResponse->json('data.reconciliation.id');

    ($this->actAs)($this->treasuryOfficer)->postJson("/api/v1/bank-reconciliations/{$recId}/lines", [
        'line_ids' => [$line->id],
        'cleared' => true,
    ])->assertOk();

    // Discard draft
    ($this->actAs)($this->treasuryOfficer)->deleteJson("/api/v1/bank-reconciliations/{$recId}")
        ->assertNoContent();

    expect(BankReconciliation::find($recId))->toBeNull()
        ->and(DB::table('bank_reconciliation_lines')->where('bank_reconciliation_id', $recId)->count())->toBe(0);

    // Refused on finished reconciliation
    $rec2 = $this->recService->start(
        $this->bankTreasury,
        '2026-10-15',
        0.0,
        $this->treasuryOfficer,
    );
    $this->recService->finish($rec2, $this->treasuryOfficer);

    ($this->actAs)($this->treasuryOfficer)->deleteJson("/api/v1/bank-reconciliations/{$rec2->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'RECONCILIATION_FINISHED');
});

test('roles: store manager can read but cannot mutate bank reconciliations', function (): void {
    $rec = $this->recService->start(
        $this->bankTreasury,
        '2026-10-15',
        100.0,
        $this->treasuryOfficer,
    );

    // Read index
    ($this->actAs)($this->storeManager)->getJson("/api/v1/bank-reconciliations?cash_account_id={$this->bankTreasury->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Read show
    ($this->actAs)($this->storeManager)->getJson("/api/v1/bank-reconciliations/{$rec->id}")
        ->assertOk()
        ->assertJsonPath('data.reconciliation.id', $rec->id);

    // Mutate start -> 403
    ($this->actAs)($this->storeManager)->postJson('/api/v1/bank-reconciliations', [
        'cash_account_id' => $this->bankTreasury->id,
        'statement_date' => '2026-10-16',
        'statement_balance' => 200.0,
    ])->assertStatus(403)
        ->assertJsonPath('code', 'RECONCILIATION_FORBIDDEN');

    // Mutate toggle -> 403
    ($this->actAs)($this->storeManager)->postJson("/api/v1/bank-reconciliations/{$rec->id}/lines", [
        'line_ids' => ['00000000-0000-0000-0000-000000000000'],
        'cleared' => true,
    ])->assertStatus(403)
        ->assertJsonPath('code', 'RECONCILIATION_FORBIDDEN');

    // Mutate finish -> 403
    ($this->actAs)($this->storeManager)->postJson("/api/v1/bank-reconciliations/{$rec->id}/finish")
        ->assertStatus(403)
        ->assertJsonPath('code', 'RECONCILIATION_FORBIDDEN');

    // Mutate discard -> 403
    ($this->actAs)($this->storeManager)->deleteJson("/api/v1/bank-reconciliations/{$rec->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'RECONCILIATION_FORBIDDEN');
});

test('index lists reconciliations newest first with summary and supports cash_account_id filter', function (): void {
    $rec1 = $this->recService->start($this->bankTreasury, '2026-10-10', 0.0, $this->treasuryOfficer);
    $this->recService->finish($rec1, $this->treasuryOfficer);

    $rec2 = $this->recService->start($this->bankTreasury, '2026-10-15', 200.0, $this->treasuryOfficer);

    $response = ($this->actAs)($this->accountant)->getJson("/api/v1/bank-reconciliations?cash_account_id={$this->bankTreasury->id}")
        ->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(2)
        ->and($data[0]['id'])->toBe($rec2->id)
        ->and($data[1]['id'])->toBe($rec1->id)
        ->and($data[0])->toHaveKey('summary')
        ->and($data[1])->toHaveKey('summary');
});
