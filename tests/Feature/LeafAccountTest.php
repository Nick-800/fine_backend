<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'unit_type' => 'store', 'currency' => 'LYD', 'status' => 'active',
    ]);

    $owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $owner->id, 'role_id' => $role->id, 'operating_unit_id' => null]);
    $this->api = fn () => $this->actingAs($owner);

    $this->id = fn (string $code) => Account::where('account_code', $code)->value('id');
});

test('a manual journal entry cannot post to a header account', function () {
    ($this->api)()->postJson('/api/v1/journal-entries', [
        'description' => 'Cash to the header',
        'lines' => [
            ['account_code' => '12', 'debit' => 100],
            ['account_code' => '40201', 'credit' => 100],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.account_code');

    expect(JournalEntry::count())->toBe(0);
});

test('a manual journal entry to leaf accounts is accepted', function () {
    ($this->api)()->postJson('/api/v1/journal-entries', [
        'description' => 'Cash sale correction',
        'lines' => [
            ['account_code' => '121102', 'debit' => 100],
            ['account_code' => '40201', 'credit' => 100],
        ],
    ])->assertCreated();
});

test('a unit event cannot be linked to a header account', function () {
    ($this->api)()->postJson("/api/v1/operating-units/{$this->unit->id}/accounts", [
        'event_type' => 'sales',
        'account_id' => ($this->id)('41'),
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    ($this->api)()->postJson("/api/v1/operating-units/{$this->unit->id}/accounts", [
        'event_type' => 'sales',
        'account_id' => ($this->id)('40201'),
    ])->assertOk();
});

test('a treasury cannot be linked to the cash group header', function () {
    ($this->api)()->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])->postJson('/api/v1/cash-accounts', [
        'name' => 'Main box',
        'kind' => 'cash',
        'account_id' => ($this->id)('1211'),
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');
});
