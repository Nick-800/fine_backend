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
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('owner can soft-delete and restore a blueprint', function () {
    $bp = UnitBlueprint::create([
        'name' => 'Foam Blueprint',
        'workflow_set' => ['production_batch' => ['planned']],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/unit-blueprints/{$bp->id}")
        ->assertOk();

    expect(UnitBlueprint::find($bp->id))->toBeNull()
        ->and(UnitBlueprint::withTrashed()->find($bp->id))->not->toBeNull();

    ($this->asOwner)()
        ->postJson("/api/v1/unit-blueprints/{$bp->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $bp->id)
        ->assertJsonPath('data.name', 'Foam Blueprint');

    expect(UnitBlueprint::find($bp->id))->not->toBeNull();
});

test('blueprint with operating units cannot be deleted', function () {
    $bp = UnitBlueprint::create([
        'name' => 'Used Blueprint',
        'workflow_set' => ['production_batch' => ['planned']],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $bp->id,
        'name' => 'A Unit',
        'unit_type' => 'manufactory',
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/unit-blueprints/{$bp->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BLUEPRINT_HAS_UNITS');

    expect(UnitBlueprint::find($bp->id))->not->toBeNull();
});

test('with_trashed=1 includes soft-deleted blueprints', function () {
    $live = UnitBlueprint::create([
        'name' => 'Live',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);
    $dead = UnitBlueprint::create([
        'name' => 'Dead',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);
    $dead->delete();

    $active = ($this->asOwner)()->getJson('/api/v1/unit-blueprints')->assertOk()->json('data');
    expect(collect($active)->pluck('name')->all())->toBe(['Live']);

    $all = ($this->asOwner)()
        ->getJson('/api/v1/unit-blueprints?with_trashed=1')
        ->assertOk()
        ->json('data');
    expect(collect($all)->pluck('name')->sort()->values()->all())->toBe(['Dead', 'Live']);
});

test('owner can update a blueprint', function () {
    $bp = UnitBlueprint::create([
        'name' => 'Original Name',
        'workflow_set' => ['production_batch' => ['planned']],
        'default_role_template' => ['foam-manager' => ['create-foam']],
        'default_inventory_config' => ['warehouse_name' => 'Yard'],
    ]);

    ($this->asOwner)()
        ->putJson("/api/v1/unit-blueprints/{$bp->id}", [
            'name' => 'Renamed',
            'workflow_set' => ['production_batch' => ['planned', 'closed']],
            'default_role_template' => ['foam-manager' => ['create-foam', 'grade-foam']],
            'default_inventory_config' => ['warehouse_name' => 'New Yard'],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $fresh = $bp->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->default_role_template)->toMatchArray(['foam-manager' => ['create-foam', 'grade-foam']]);
});
