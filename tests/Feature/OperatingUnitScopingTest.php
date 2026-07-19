<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Support\CurrentUnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Unit A',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Unit B',
        'unit_type' => 'manufactory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->managerRole = Role::create([
        'name' => 'Manager',
        'slug' => 'manager',
    ]);

    $this->user = User::create([
        'name' => 'Manager User',
        'email' => 'manager@example.com',
        'password' => Hash::make('password'),
    ]);

    // Scoped to Unit A
    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->managerRole->id,
        'operating_unit_id' => $this->unitA->id,
    ]);

    // Create warehouses for both units
    $this->warehouseA = Warehouse::create([
        'operating_unit_id' => $this->unitA->id,
        'name' => 'Warehouse A',
    ]);

    $this->warehouseB = Warehouse::create([
        'operating_unit_id' => $this->unitB->id,
        'name' => 'Warehouse B',
    ]);
});

it('blocks request without unit header for unit-scoped user', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/operating-units');

    $response->assertStatus(400); // Missing header
});

it('blocks request with unit header the user does not have access to', function (): void {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unitB->id)
        ->getJson('/api/v1/operating-units');

    $response->assertStatus(403); // Access Denied
});

it('allows request with valid unit header and scopes results', function (): void {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unitA->id)
        ->getJson('/api/v1/operating-units');

    $response->assertStatus(200);

    // Verify database query is automatically scoped to Unit A warehouses
    $warehouses = Warehouse::all();
    expect($warehouses)->toHaveCount(1);
    expect($warehouses[0]->id)->toBe($this->warehouseA->id);
});

it('automatically sets operating_unit_id on creation of scoped models', function (): void {
    // Manually set unit context for the test execution thread
    app(CurrentUnitContext::class)->setUnit($this->unitA);

    $warehouse = Warehouse::create([
        'name' => 'Auto Scoped Warehouse',
    ]);

    expect($warehouse->operating_unit_id)->toBe($this->unitA->id);
});
