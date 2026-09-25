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
use Database\Seeders\OperatingUnitChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Scope Test', 'default_currency' => 'LYD']);
    $this->seed(ChartOfAccountsTestSeeder::class);

    // The chart seeder looks up its two units by name. Pre-create them
    // so the seeder populates its foam + cutter charts.
    $this->blueprint = UnitBlueprint::create([
        'name' => 'BP', 'workflow_set' => '[]', 'default_role_template' => '[]', 'default_inventory_config' => '[]',
    ]);
    $this->foam = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'مصنع الإسفنج', 'code' => 'FOAM-S', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->cutter = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'قسم القص', 'code' => 'CUT-S', 'unit_type' => 'manufactory', 'status' => 'active',
    ]);
    $this->seed(OperatingUnitChartSeeder::class);

    // A "third unit" — a unit-scoped caller with no foam/cutter chart.
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

test('scope=global returns only the standard global chart', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts')
        ->assertStatus(200);
    expect($r->json('meta.scope'))->toBe('global')
        ->and($r->json('meta.count'))->toBe(36)
        ->and(collect($r->json('data'))->pluck('unit_id')->every(fn ($u) => $u === null))->toBeTrue();
});

test('scope=unit with foam id returns only foam rows; cutter excluded (the bug)', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts?scope=unit&unit_id='.$this->foam->id)
        ->assertStatus(200);

    expect($r->json('meta.scope'))->toBe('unit')
        ->and($r->json('meta.count'))->toBe(492)
        ->and(collect($r->json('data'))->pluck('unit_id')->every(fn ($u) => $u === $this->foam->id))->toBeTrue();

    $r2 = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts?scope=unit&unit_id='.$this->cutter->id)
        ->assertStatus(200);
    expect($r2->json('meta.count'))->toBe(333)
        ->and(collect($r2->json('data'))->pluck('unit_id')->every(fn ($u) => $u === $this->cutter->id))->toBeTrue();
});

test('scope=unit without unit_id and without auth unit -> 422', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts?scope=unit')
        ->assertStatus(422);
    expect($r->json('code'))->toBe('UNIT_ID_REQUIRED');
});

test('scope=unit without unit_id but with auth unit -> defaults to caller unit', function () {
    $r = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts?scope=unit')
        ->assertStatus(200);
    expect($r->json('meta.scope'))->toBe('unit');
    foreach ($r->json('data') as $a) {
        expect($a['unit_id'])->toBe($this->unit->id);
    }
});

test('scope=company as accounting-manager -> returns global plus every unit', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts?scope=company')
        ->assertStatus(200);
    expect($r->json('meta.count'))->toBe(36 + 492 + 333);
});

test('scope=company as unit manager -> 403', function () {
    $r = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts?scope=company')
        ->assertStatus(403);
    expect($r->json('code'))->toBe('COMPANY_WIDE_FORBIDDEN');
});

test('unit caller asking for another unit -> 403 CROSS_UNIT_SCOPE', function () {
    $r = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts?scope=unit&unit_id='.$this->cutter->id)
        ->assertStatus(403);
    expect($r->json('code'))->toBe('CROSS_UNIT_SCOPE');
});

test('no scope param defaults to scope=global for both auth contexts', function () {
    $r1 = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts')
        ->assertStatus(200);
    expect($r1->json('meta.scope'))->toBe('global');

    $r2 = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts')
        ->assertStatus(200);
    expect($r2->json('meta.scope'))->toBe('global');
});

test('show refuses cross-unit access with ACCOUNT_WRONG_UNIT', function () {
    $cutterAccount = Account::where('unit_id', $this->cutter->id)->first();

    $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/accounts/'.$cutterAccount->id)
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_WRONG_UNIT');
});

test('update refuses cross-unit access', function () {
    $cutterAccount = Account::where('unit_id', $this->cutter->id)->first();

    $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->putJson('/api/v1/accounts/'.$cutterAccount->id, ['name' => 'hacked'])
        ->assertStatus(403);
});

test('destroy refuses cross-unit access', function () {
    $cutterAccount = Account::where('unit_id', $this->cutter->id)->first();

    $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->deleteJson('/api/v1/accounts/'.$cutterAccount->id)
        ->assertStatus(403);
});

test('global accounting-manager can read any unit-scoped account', function () {
    $cutterAccount = Account::where('unit_id', $this->cutter->id)->first();

    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/accounts/'.$cutterAccount->id)
        ->assertStatus(200);
    expect($r->json('data.id'))->toBe($cutterAccount->id);
});

test('trial-balance endpoint respects the same scope', function () {
    $r = $this->actingAs($this->globalUser)
        ->getJson('/api/v1/reports/trial-balance?scope=company')
        ->assertStatus(200);
    expect($r->json('balanced'))->toBeTrue();

    $r2 = $this->actingAs($this->unitUser)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id])
        ->getJson('/api/v1/reports/trial-balance?scope=unit')
        ->assertStatus(200);
    expect($r2->json('balanced'))->toBeTrue();
});
