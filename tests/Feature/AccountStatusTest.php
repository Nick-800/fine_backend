<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\CashAccount;
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

    $blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);

    $this->accounting = app(AccountingService::class);
    $this->cash = Account::where('account_code', '121101')->sole();
    $this->freeze = fn (Account $account) => $this->actingAs($this->owner)
        ->patchJson("/api/v1/accounts/{$account->id}/status", ['status' => 'frozen']);
    $this->postCash = fn () => $this->accounting->postJournal('Cash sale', [
        ['account_code' => '121101', 'debit' => 10.0],
        ['account_code' => '40201', 'credit' => 10.0],
    ]);
});

test('a frozen account refuses automatic and manual postings (ACC-14)', function () {
    ($this->freeze)($this->cash)->assertOk()->assertJsonPath('data.status', 'frozen');

    expect(fn () => ($this->postCash)())->toThrow(LedgerRuleException::class, 'frozen');

    $this->actingAs($this->owner)->postJson('/api/v1/journal-entries', [
        'description' => 'Manual',
        'lines' => [['account_code' => '121101', 'debit' => 5], ['account_code' => '31', 'credit' => 5]],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.account_code');
});

test('freezing a header freezes every account under it', function () {
    ($this->freeze)(Account::where('account_code', '1211')->sole())->assertOk();

    expect(fn () => ($this->postCash)())->toThrow(LedgerRuleException::class);
    expect(Account::where('account_code', '121102')->sole()->isFrozen())->toBeTrue()
        ->and(Account::where('account_code', '1212')->sole()->isFrozen())->toBeFalse();
});

test('reactivating restores postings, and a reversal still posts onto a frozen account', function () {
    $entry = ($this->postCash)();
    ($this->freeze)($this->cash)->assertOk();

    $reversal = $this->accounting->reverseEntry($entry, null, 'Undo the sale');
    expect($reversal->lines)->toHaveCount(2);

    $this->actingAs($this->owner)->patchJson("/api/v1/accounts/{$this->cash->id}/status", ['status' => 'active'])->assertOk();
    expect(($this->postCash)()->id)->not->toBeNull();
});

test('a frozen account cannot be newly linked, but an existing link can be re-saved', function () {
    $treasury = CashAccount::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash', 'account_id' => $this->cash->id,
    ]);
    ($this->freeze)($this->cash)->assertOk();

    $this->actingAs($this->owner)->putJson("/api/v1/cash-accounts/{$treasury->id}", ['name' => 'Drawer A', 'account_id' => $this->cash->id])
        ->assertOk();

    $this->actingAs($this->owner)->postJson('/api/v1/cash-accounts', [
        'operating_unit_id' => $this->unit->id, 'name' => 'New drawer', 'kind' => 'cash', 'account_id' => $this->cash->id,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');
});

test('two accounts under the same parent cannot share a name', function () {
    $parent = Account::where('account_code', '1211')->sole();

    $this->actingAs($this->owner)->postJson('/api/v1/accounts', [
        'account_code' => '121199', 'name' => '  خزينة مصنع الإسفنج ', 'type' => 'asset', 'parent_account_id' => $parent->id,
    ])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NAME_DUPLICATE')->assertJsonPath('account_code', '121101');

    $this->actingAs($this->owner)->postJson('/api/v1/accounts', [
        'account_code' => '121299', 'name' => 'خزينة مصنع الإسفنج', 'type' => 'asset',
        'parent_account_id' => Account::where('account_code', '1212')->value('id'),
    ])->assertCreated();

    $this->actingAs($this->owner)->putJson('/api/v1/accounts/'.Account::where('account_code', '121102')->value('id'), ['name' => 'خزينة مصنع الإسفنج'])
        ->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NAME_DUPLICATE');
});

test('only accounting managers and the owner change an account status', function () {
    $cashier = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Cashier', 'slug' => 'pos-cashier']);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($cashier)->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->patchJson("/api/v1/accounts/{$this->cash->id}/status", ['status' => 'frozen'])
        ->assertForbidden();

    expect($this->cash->fresh()->status)->toBe(AccountStatus::Active);
});

test('accounts list and detail report their status', function () {
    ($this->freeze)($this->cash)->assertOk();

    $row = collect($this->actingAs($this->owner)->getJson('/api/v1/accounts')->json('data'))->firstWhere('account_code', '121101');
    expect($row['status'])->toBe('frozen');

    $this->actingAs($this->owner)->getJson("/api/v1/accounts/{$this->cash->id}")->assertJsonPath('data.status', 'frozen');
});
