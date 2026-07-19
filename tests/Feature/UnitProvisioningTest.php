<?php

declare(strict_types=1);

use App\Models\OperatingUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::create([
        'name' => 'Owner User',
        'email' => 'owner@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->ownerRole = Role::create([
        'name' => 'Owner',
        'slug' => 'owner',
    ]);

    UserRole::create([
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'operating_unit_id' => null, // Company-wide Owner
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Plant Blueprint',
        'workflow_set' => ['batch' => ['planned', 'closed']],
        'default_role_template' => [
            'foam-operator' => ['view-foam', 'edit-foam'],
        ],
        'default_inventory_config' => [
            'warehouse_name' => 'Main Block Store',
        ],
    ]);
});

it('can provision an operating unit end-to-end via provisioning endpoint', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/operating-units', [
            'blueprint_id' => $this->blueprint->id,
            'name' => 'Tajoura Foam Manufactory',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Tajoura Foam Manufactory')
        ->assertJsonPath('data.status', 'active');

    $unitId = $response->json('data.id');

    // Assert OperatingUnit exists in database
    $unit = OperatingUnit::findOrFail($unitId);
    expect($unit->name)->toBe('Tajoura Foam Manufactory');

    // Assert scoped Warehouse exists in database
    $warehouse = Warehouse::where('operating_unit_id', $unitId)->first();
    expect($warehouse)->not->toBeNull();
    expect($warehouse->name)->toBe('Tajoura Foam Manufactory Main Block Store');

    // Assert role was generated in database
    $role = Role::where('slug', 'foam-operator')->first();
    expect($role)->not->toBeNull();

    // Assert permission was mapped in database
    $permission = Permission::where('slug', 'view-foam')->first();
    expect($permission)->not->toBeNull();
    expect($role->permissions->contains($permission))->toBeTrue();
});
