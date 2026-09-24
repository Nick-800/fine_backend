<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\PayableSettlement;
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
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->manager = User::factory()->create(['must_change_password' => false]);
    $managerRole = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);
    UserRole::create(['user_id' => $this->manager->id, 'role_id' => $managerRole->id, 'operating_unit_id' => $this->unit->id]);

    $accounting = app(AccountingService::class);

    // Accumulate the three payables the way the flows do: a credit purchase,
    // payroll withholdings, and landed costs awaiting their providers.
    $accounting->postJournal('Credit purchase', [
        ['account_code' => '111', 'debit' => 500.0],
        ['account_code' => '21', 'credit' => 500.0],
    ]);
    $accounting->postJournal('Payroll withholdings', [
        ['account_code' => '57', 'debit' => 80.0],
        ['account_code' => '221', 'credit' => 80.0],
    ]);
    $accounting->postJournal('Landed costs', [
        ['account_code' => '111', 'debit' => 300.0],
        ['account_code' => '23', 'credit' => 300.0],
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner);

    $this->settle = fn (array $overrides = []) => ($this->asOwner)()
        ->postJson('/api/v1/payable-settlements', array_merge([
            'account_code' => '21',
            'amount' => 200,
            'reference' => 'CHK-001',
        ], $overrides));
});

test('settling a payable pays cash and reduces the outstanding balance', function () {
    ($this->settle)()->assertStatus(201);

    $settlement = PayableSettlement::sole();
    expect((float) $settlement->amount)->toBe(200.0)
        ->and($settlement->settled_by_user_id)->toBe($this->owner->id);

    $entry = JournalEntry::where('source_document_type', 'PayableSettlement')
        ->where('source_document_id', $settlement->id)->sole();
    $lines = $entry->lines()->with('account')->get();

    expect((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '21')->debit)->toBe(200.0)
        ->and((float) $lines->firstWhere(fn ($l) => $l->account->account_code === '12')->credit)->toBe(200.0);

    $outstanding = ($this->asOwner)()->getJson('/api/v1/payable-settlements/outstanding')
        ->assertStatus(200)->json('data');

    expect((float) collect($outstanding)->firstWhere('account_code', '21')['outstanding'])->toBe(300.0)
        ->and((float) collect($outstanding)->firstWhere('account_code', '221')['outstanding'])->toBe(80.0)
        ->and((float) collect($outstanding)->firstWhere('account_code', '23')['outstanding'])->toBe(300.0);
});

test('a settlement can never exceed what the ledger says is owed', function () {
    ($this->settle)(['amount' => 600])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SETTLEMENT');

    // Pay it all, then even one more dinar is refused.
    ($this->settle)(['amount' => 500])->assertStatus(201);
    ($this->settle)(['amount' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SETTLEMENT');

    expect(PayableSettlement::count())->toBe(1);
});

test('each payable settles independently and the books stay balanced', function () {
    ($this->settle)(['account_code' => '221', 'amount' => 80, 'reference' => 'tax remittance'])->assertStatus(201);
    ($this->settle)(['account_code' => '23', 'amount' => 300, 'reference' => 'customs broker'])->assertStatus(201);

    $outstanding = collect(($this->asOwner)()->getJson('/api/v1/payable-settlements/outstanding')->json('data'));

    expect((float) $outstanding->firstWhere('account_code', '221')['outstanding'])->toBe(0.0)
        ->and((float) $outstanding->firstWhere('account_code', '23')['outstanding'])->toBe(0.0)
        ->and((float) $outstanding->firstWhere('account_code', '21')['outstanding'])->toBe(500.0);

    $tb = ($this->asOwner)()->getJson('/api/v1/reports/trial-balance')->assertStatus(200)->json();
    expect($tb['balanced'])->toBeTrue();
});

test('a unit-tagged settlement is capped by that unit\'s own subledger', function () {
    // 100 of AP belongs to the foam unit; the 500 above is company-level.
    app(AccountingService::class)->postJournal('Unit credit purchase', [
        ['account_code' => '111', 'debit' => 100.0, 'operating_unit_id' => $this->unit->id],
        ['account_code' => '21', 'credit' => 100.0, 'operating_unit_id' => $this->unit->id],
    ]);

    ($this->settle)(['amount' => 100, 'operating_unit_id' => $this->unit->id])->assertStatus(201);

    // The unit's subledger is empty now — company-level balance cannot be
    // raided through a unit-tagged settlement.
    ($this->settle)(['amount' => 50, 'operating_unit_id' => $this->unit->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SETTLEMENT');
});

test('only accounting-level roles can settle and accounts are whitelisted', function () {
    $this->actingAs($this->manager)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->postJson('/api/v1/payable-settlements', ['account_code' => '21', 'amount' => 10])
        ->assertStatus(403)
        ->assertJsonPath('code', 'SETTLEMENT_FORBIDDEN');

    // 1200 is cash, not a payable — the whitelist refuses it outright.
    ($this->settle)(['account_code' => '12'])->assertStatus(422);

    expect(PayableSettlement::count())->toBe(0);
});

test('settlements are listed with their audit trail', function () {
    ($this->settle)()->assertStatus(201);

    $listed = ($this->asOwner)()->getJson('/api/v1/payable-settlements')
        ->assertStatus(200)->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['account_code'])->toBe('21')
        ->and($listed[0]['reference'])->toBe('CHK-001')
        ->and($listed[0]['settled_by']['id'])->toBe($this->owner->id);
});
