<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine User Mgmt Corp',
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

function makeUserWithRole(string $roleSlug, ?OperatingUnit $unit = null): User
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

function attachPermission(Role $role, string $permissionSlug, string $module = 'users', string $action = 'manage'): void
{
    $permission = Permission::firstOrCreate(
        ['slug' => $permissionSlug],
        [
            'name' => ucwords(str_replace('-', ' ', $permissionSlug)),
            'module' => $module,
            'action' => $action,
        ],
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);
}

it('lets an owner update another user name and email', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'record_version' => $target->record_version,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new@example.com');

    $target->refresh();
    expect($target->name)->toBe('New Name')
        ->and($target->email)->toBe('new@example.com')
        ->and($target->record_version)->toBe(2);
});

it('lets an owner toggle another user is_active', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create(['is_active' => true]);

    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'is_active' => false,
            'record_version' => $target->record_version,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($target->fresh()->is_active)->toBeFalse();
});

it('lets an owner reset a user password and force a change', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create([
        'password' => Hash::make('oldPass1234'),
        'must_change_password' => false,
    ]);
    $oldHash = $target->password;

    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'password' => 'newPass1234',
            'record_version' => $target->record_version,
        ])
        ->assertOk();

    $target->refresh();
    expect($target->password)->not->toBe($oldHash)
        ->and(Hash::check('newPass1234', $target->password))->toBeTrue()
        ->and($target->must_change_password)->toBeTrue();
});

it('returns 409 when updating with a stale record_version', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create();

    // First update succeeds, bumps record_version to 2.
    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'name' => 'First edit',
            'record_version' => $target->record_version,
        ])
        ->assertOk();

    // Second update with the original (now stale) version fails.
    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Stale edit',
            'record_version' => 1,
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The record has been updated by another user.');
});

it('rejects an email that is already used by another user', function () {
    $owner = makeUserWithRole('owner');
    User::factory()->create(['email' => 'taken@example.com']);
    $target = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($owner)
        ->putJson("/api/v1/users/{$target->id}", [
            'email' => 'taken@example.com',
            'record_version' => $target->record_version,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('lets an owner soft-delete a non-owner user', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create();

    $this->actingAs($owner)
        ->deleteJson("/api/v1/users/{$target->id}")
        ->assertOk()
        ->assertJsonPath('message', 'User deleted successfully.');

    expect($target->fresh()->trashed())->toBeTrue();
});

it('forbids an owner from deleting their own account', function () {
    $owner = makeUserWithRole('owner');

    $this->actingAs($owner)
        ->deleteJson("/api/v1/users/{$owner->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot delete your own account. Ask another owner.');

    expect($owner->fresh()->trashed())->toBeFalse();
});

it('forbids a user without delete permission from deleting another user', function () {
    makeUserWithRole('owner'); // an owner exists but is not the acting user
    $actor = makeUserWithRole('viewer');
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->id}")
        ->assertStatus(403);

    expect($target->fresh()->trashed())->toBeFalse();
});

it('blocks any non-owner from hitting /users routes even with manage-users permission', function () {
    // /users is gated by `require.role:owner` middleware, so even a user
    // with the manage-users permission is rejected at the route level. The
    // policy gate is a defense-in-depth check, not the primary gate.
    makeUserWithRole('owner');
    $managerRole = Role::firstOrCreate(
        ['slug' => 'users-manager'],
        ['name' => 'Users Manager'],
    );
    attachPermission($managerRole, 'manage-users');
    attachPermission($managerRole, 'edit-users');

    $actor = makeUserWithRole('users-manager');
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Updated by manager',
            'record_version' => $target->record_version,
        ])
        ->assertStatus(403);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/users/{$target->id}")
        ->assertStatus(403);

    expect($target->fresh()->trashed())->toBeFalse();
});

it('allows owner to fetch user details including roles, permissions, and deleted_at', function () {
    $owner = makeUserWithRole('owner');
    $target = User::factory()->create([
        'name' => 'Sara Connor',
        'email' => 'sara@example.com',
    ]);
    $role = Role::firstOrCreate(['slug' => 'sales-officer'], ['name' => 'Sales Officer']);
    attachPermission($role, 'sales.create');
    UserRole::create([
        'user_id' => $target->id,
        'role_id' => $role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $res = $this->actingAs($owner)
        ->getJson("/api/v1/users/{$target->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Sara Connor')
        ->assertJsonPath('data.email', 'sara@example.com')
        ->assertJsonPath('data.deleted_at', null);

    expect($res->json('data.permissions'))->toContain('sales.create')
        ->and($res->json('data.roles'))->toHaveCount(1);
});

