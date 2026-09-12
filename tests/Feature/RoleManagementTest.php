<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Permissions the tests will sync onto roles.
    $this->viewInventory = Permission::firstOrCreate(
        ['slug' => 'view-inventory'],
        ['name' => 'View Inventory', 'module' => 'inventory', 'action' => 'view'],
    );
    $this->manageUsers = Permission::firstOrCreate(
        ['slug' => 'manage-users'],
        ['name' => 'Manage Users', 'module' => 'users', 'action' => 'manage'],
    );
});

function seedOwnerUser(): User
{
    $owner = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $role->id,
        'operating_unit_id' => null,
    ]);

    return $owner;
}

it('lets an owner list roles and the permission catalog', function () {
    $owner = seedOwnerUser();

    Role::firstOrCreate(['slug' => 'accounting-manager'], ['name' => 'Accounting Manager']);

    $this->actingAs($owner)
        ->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(2, 'data'); // owner + accounting-manager

    $this->actingAs($owner)
        ->getJson('/api/v1/permissions')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'module', 'action']]]);
});

it('lets an owner update a role name and description', function () {
    $owner = seedOwnerUser();
    $role = Role::create(['name' => 'Old Name', 'slug' => 'old-name', 'description' => 'old']);

    $this->actingAs($owner)
        ->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'New Name',
            'description' => 'new desc',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.description', 'new desc')
        ->assertJsonPath('data.slug', 'old-name'); // slug stays immutable

    $role->refresh();
    expect($role->name)->toBe('New Name')
        ->and($role->description)->toBe('new desc')
        ->and($role->slug)->toBe('old-name');
});

it('lets an owner sync a role permission set', function () {
    $owner = seedOwnerUser();
    $role = Role::create(['name' => 'Custom', 'slug' => 'custom-role']);
    $role->permissions()->attach($this->viewInventory->id);

    $response = $this->actingAs($owner)
        ->putJson("/api/v1/roles/{$role->id}", [
            'permission_ids' => [$this->manageUsers->id, $this->viewInventory->id],
        ])
        ->assertOk();

    $expected = collect([$this->manageUsers->id, $this->viewInventory->id])
        ->sort()->values()->all();

    $returnedIds = collect($response->json('data.permission_ids'))->sort()->values()->all();
    expect($returnedIds)->toBe($expected);

    $pivotIds = $role->fresh()->permissions->pluck('id')->sort()->values()->all();
    expect($pivotIds)->toBe($expected);
});

it('protects the owner and admin roles from permission edits', function () {
    $owner = seedOwnerUser();
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $adminRole->permissions()->attach($this->viewInventory->id);

    // Sending a tiny permission set on admin must not strip its existing pivots.
    $this->actingAs($owner)
        ->putJson("/api/v1/roles/{$adminRole->id}", [
            'permission_ids' => [$this->manageUsers->id],
        ])
        ->assertOk();

    expect($adminRole->fresh()->permissions->pluck('id')->all())
        ->toContain($this->viewInventory->id);
});

it('lets an owner delete a non-protected role', function () {
    $owner = seedOwnerUser();
    $role = Role::create(['name' => 'Throwaway', 'slug' => 'throwaway']);

    $this->actingAs($owner)
        ->deleteJson("/api/v1/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Role deleted successfully.');

    expect(Role::find($role->id))->toBeNull();
});

it('refuses to delete the owner role', function () {
    $owner = seedOwnerUser();
    $ownerRole = Role::where('slug', 'owner')->firstOrFail();

    $this->actingAs($owner)
        ->deleteJson("/api/v1/roles/{$ownerRole->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'The owner and admin roles are protected and cannot be deleted.');

    expect(Role::find($ownerRole->id))->not->toBeNull();
});

it('refuses to delete the admin role', function () {
    $owner = seedOwnerUser();
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

    $this->actingAs($owner)
        ->deleteJson("/api/v1/roles/{$adminRole->id}")
        ->assertStatus(422);

    expect(Role::find($adminRole->id))->not->toBeNull();
});

it('blocks any non-owner from hitting /roles or /permissions routes', function () {
    // /roles is gated by require.role:owner middleware, so even a user
    // with manage-users permission is rejected at the route layer.
    $actor = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'users-manager'], ['name' => 'Users Manager']);
    $perm = Permission::firstOrCreate(
        ['slug' => 'manage-users'],
        ['name' => 'Manage Users', 'module' => 'users', 'action' => 'manage'],
    );
    $role->permissions()->attach($perm->id);
    UserRole::create([
        'user_id' => $actor->id,
        'role_id' => $role->id,
        'operating_unit_id' => null,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/roles')->assertStatus(403);
    $this->actingAs($actor)->putJson('/api/v1/roles/00000000-0000-0000-0000-000000000000', [])
        ->assertStatus(403);
    $this->actingAs($actor)->deleteJson('/api/v1/roles/00000000-0000-0000-0000-000000000000')
        ->assertStatus(403);
    $this->actingAs($actor)->getJson('/api/v1/permissions')->assertStatus(403);
});
