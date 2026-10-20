<?php

declare(strict_types=1);

use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

    $accounting = app(AccountingService::class);
    $this->kept = $accounting->postJournal('Kept', [
        ['account_code' => '121101', 'debit' => 100.0],
        ['account_code' => '31', 'credit' => 100.0],
    ]);
    $this->deleted = $accounting->postJournal('Soft-deleted', [
        ['account_code' => '121101', 'debit' => 50.0],
        ['account_code' => '31', 'credit' => 50.0],
    ]);

    // Maintenance-level soft delete: model events would refuse it (ACC-13).
    DB::table('journal_entries')->where('id', $this->deleted->id)->update(['deleted_at' => now()]);

    $this->cash = Account::where('account_code', '121101')->sole();
    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('lines of soft-deleted entries are left out of the trial balance and balances (ACC-16)', function () {
    $row = collect(app(AccountingService::class)->trialBalance()['rows'])->firstWhere('account_code', '121101');
    expect((float) $row['debit'])->toBe(100.0);

    $index = collect(($this->asOwner)()->getJson('/api/v1/accounts')->assertOk()->json('data'))
        ->firstWhere('account_code', '121101');
    expect((float) $index['balance'])->toBe(100.0);

    $show = ($this->asOwner)()->getJson("/api/v1/accounts/{$this->cash->id}")->assertOk()->json('data');
    expect((float) $show['total_debit'])->toBe(100.0)
        ->and($show['transaction_count'])->toBe(1);

    $sheet = ($this->asOwner)()->getJson('/api/v1/reports/balance-sheet')->assertOk()->json();
    expect((float) collect($sheet['assets']['rows'] ?? $sheet['assets'] ?? [])->firstWhere('account_code', '121101')['balance'])->toBe(100.0);
});

test('posted entries and lines cannot be changed or deleted through the models (ACC-13)', function () {
    expect(fn () => $this->kept->update(['description' => 'Edited']))->toThrow(LedgerRuleException::class)
        ->and(fn () => $this->kept->delete())->toThrow(LedgerRuleException::class)
        ->and(fn () => JournalLine::where('journal_entry_id', $this->kept->id)->first()->delete())->toThrow(LedgerRuleException::class);

    expect(JournalEntry::find($this->kept->id)->description)->toBe('Kept');
});

test('an account with postings is never deleted, even when force is asked for', function () {
    ($this->asOwner)()->deleteJson("/api/v1/accounts/{$this->cash->id}?force=1")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_HAS_TRANSACTIONS');

    expect(JournalLine::where('account_id', $this->cash->id)->count())->toBe(2);
});

test('an account still linked from a treasury is not deleted', function () {
    $parent = Account::where('account_code', '1211')->sole();
    $leaf = Account::create([
        'chart_of_accounts_id' => $parent->chart_of_accounts_id, 'parent_account_id' => $parent->id,
        'account_code' => '121199', 'name' => 'Spare drawer', 'type' => 'asset', 'currency' => 'LYD',
    ]);
    CashAccount::create(['operating_unit_id' => $this->unit->id, 'name' => 'Drawer', 'kind' => 'cash', 'account_id' => $leaf->id]);

    ($this->asOwner)()->deleteJson("/api/v1/accounts/{$leaf->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_IN_USE')
        ->assertJsonPath('table', 'cash_accounts');
});

test('the code of an account with postings is locked', function () {
    ($this->asOwner)()->putJson("/api/v1/accounts/{$this->cash->id}", ['name' => $this->cash->name, 'account_code' => '121199'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_CODE_LOCKED');

    ($this->asOwner)()->putJson("/api/v1/accounts/{$this->cash->id}", ['name' => 'خزينة المصنع'])
        ->assertOk();
});

test('the chart cannot be wiped once anything is posted, force or not', function () {
    ($this->asOwner)()->postJson('/api/v1/accounts/wipe', ['force' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_HAS_TRANSACTIONS');

    expect(JournalLine::count())->toBe(4);
});

test('the account ledger includes entries dated on the to day', function () {
    $date = $this->kept->entry_date->toDateString();

    $lines = ($this->asOwner)()->getJson("/api/v1/accounts/{$this->cash->id}/ledger?from={$date}&to={$date}")
        ->assertOk()->json('data');

    expect($lines)->toHaveCount(1);
});
