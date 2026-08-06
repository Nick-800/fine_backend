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
        'name' => 'Test Corporation',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => true,
        'transfer_pricing_mode' => 'cost_plus',
        'timezone' => 'UTC',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Standard Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->operatingUnit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Main Operating Unit',
        'unit_type' => 'factory',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->role = Role::create([
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->user = User::factory()->create([
        'must_change_password' => false,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->operatingUnit->id,
    ]);
});

test('authenticated user can list and store suppliers', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->postJson('/api/v1/suppliers', [
            'operating_unit_id' => $this->operatingUnit->id,
            'name' => 'Global Logistics Inc',
            'contact' => 'john@globallogistics.com',
            'default_currency' => 'USD',
            'address' => '100 Harbor Way',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Global Logistics Inc')
        ->assertJsonPath('data.default_currency', 'USD');

    $listResponse = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->operatingUnit->id)
        ->getJson('/api/v1/suppliers?operating_unit_id='.$this->operatingUnit->id);

    $listResponse->assertStatus(200)
        ->assertJsonCount(1, 'data');
});
