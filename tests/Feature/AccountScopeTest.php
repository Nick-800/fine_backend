<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Scope Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP', 'workflow_set' => '[]', 'default_role_template' => '[]', 'default_inventory_config' => '[]',
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Test Unit', 'code' => 'SCOPE-1', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);

    $this->globalRole = Role::create(['name' => 'AM', 'slug' => 'accounting-manager']);
    $this->unitRole = Role::create(['name' => 'UM', 'slug' => 'unit_manager']);

    $this->globalUser = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->globalUser->id,
        'role_id' => $this->globalRole->id,
        'operating_unit_id' => null,
    ]);

    $this->unitUser = User::factory()->create(['must_change_password' => false]);
    UserRole::create([
        'user_id' => $this->unitUser->id,
        'role_id' => $this->unitRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

test('GET /api/v1/accounts returns unified company chart for both global and unit callers', function () {
    $r1 = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts')
        ->assertStatus(200);

    $r2 = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts')
        ->assertStatus(200);

    expect($r1->json('meta.count'))->toBe($r2->json('meta.count'))
        ->and($r1->json('meta.count'))->toBeGreaterThanOrEqual(5);
});

test('accounts are accessible across units without scope errors', function () {
    $account = Account::first();

    $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts/'.$account->id)
        ->assertStatus(200)
        ->assertJsonPath('data.account_code', $account->account_code);
});

test('trial-balance endpoint functions across unified chart', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/reports/trial-balance')
        ->assertStatus(200);

    expect($r->json('balanced'))->toBeTrue();
});
