<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine RBAC Test Corp',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'General Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Unit',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);
});

function createRbacUser(string $roleSlug, ?OperatingUnit $unit = null): User
{
    $user = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucwords(str_replace('-', ' ', $roleSlug))]);

    UserRole::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'operating_unit_id' => $unit?->id,
    ]);

    return $user;
}

test('owner has unrestricted access to all protected route groups', function () {
    $owner = createRbacUser('owner');

    $this->actingAs($owner)
        ->getJson('/api/v1/users')
        ->assertOk();

    $this->actingAs($owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/payment-requests')
        ->assertOk();

    $this->actingAs($owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/production-batches')
        ->assertOk();
});

test('pos-cashier is granted POS and sales access but denied admin, hr and manufacturing', function () {
    $cashier = createRbacUser('pos-cashier', $this->unit);

    // Allowed
    $this->actingAs($cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/sales-orders')
        ->assertOk();

    // Denied Admin
    $this->actingAs($cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/users')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');

    // Denied HR
    $this->actingAs($cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/payroll-runs')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');

    // Denied Foam Manufacturing
    $this->actingAs($cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/production-batches')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');
});

test('foam-operator can access batches and inventory but is denied treasury and admin', function () {
    $operator = createRbacUser('foam-operator', $this->unit);

    // Allowed Foam batches
    $this->actingAs($operator)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/production-batches')
        ->assertOk();

    // Denied Treasury
    $this->actingAs($operator)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/payment-requests')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');

    // Denied Admin User list
    $this->actingAs($operator)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/users')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');
});

test('treasury-officer can access treasury routes and cash accounts', function () {
    $treasury = createRbacUser('treasury-officer', $this->unit);

    $this->actingAs($treasury)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/payment-requests')
        ->assertOk();

    $this->actingAs($treasury)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/cash-accounts')
        ->assertOk();

    // Denied Foam
    $this->actingAs($treasury)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/production-batches')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCESS_DENIED');
});

test('user resource includes compiled role_slugs and permissions', function () {
    $operator = createRbacUser('foam-operator', $this->unit);

    $res = $this->actingAs($operator)->getJson('/api/v1/auth/me');
    $res->assertOk();

    expect($res->json('data.role_slugs'))->toContain('foam-operator')
        ->and($res->json('data.permissions'))->toBeArray();

    $owner = createRbacUser('owner');
    $ownerRes = $this->actingAs($owner)->getJson('/api/v1/auth/me');
    $ownerRes->assertOk();

    expect($ownerRes->json('data.role_slugs'))->toContain('owner')
        ->and($ownerRes->json('data.permissions'))->toContain('*');
});
