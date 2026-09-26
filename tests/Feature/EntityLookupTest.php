<?php

declare(strict_types=1);

use App\Enums\EntityType;
use App\Models\Company;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create([
        'name' => 'Test Company',
        'default_currency' => 'LYD',
        'transfer_pricing_mode' => 'at_cost',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Test Blueprint',
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

    $this->ownerRole = Role::create([
        'name' => 'Owner',
        'slug' => 'owner',
    ]);

    $this->admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'must_change_password' => false,
        'is_active' => true,
    ]);

    UserRole::create([
        'user_id' => $this->admin->id,
        'role_id' => $this->ownerRole->id,
        'operating_unit_id' => $this->unit->id,
    ]);
});

it('lists entities successfully without relation errors', function (): void {
    Entity::create([
        'name' => 'Test Organization',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/entities');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Test Organization');
});

it('filters entities by entity_type and role_type', function (): void {
    $org = Entity::create([
        'name' => 'Org A',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $ind = Entity::create([
        'name' => 'Individual B',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
    ]);

    $org->roles()->create(['role_type' => 'client']);

    $typeResponse = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/entities?entity_type=individual');

    $typeResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Individual B');

    $roleResponse = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/entities?role_type=client');

    $roleResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Org A');
});

it('creates client with city and includes city in response', function (): void {
    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/clients', [
            'name' => 'Al-Sahara Trading',
            'operating_unit_id' => $this->unit->id,
            'entity_type' => 'organization',
            'city' => 'طبرق',
            'address' => 'شارع الميناء',
            'credit_limit' => 50000,
            'payment_terms_days' => 45,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.entity.name', 'Al-Sahara Trading')
        ->assertJsonPath('data.entity.city', 'طبرق')
        ->assertJsonPath('data.entity.address', 'شارع الميناء');

    $listResponse = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/clients');

    $listResponse->assertStatus(200)
        ->assertJsonPath('data.0.entity.city', 'طبرق');
});
