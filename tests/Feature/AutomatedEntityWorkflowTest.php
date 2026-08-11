<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\EntityType;
use App\Enums\PayType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
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

it('automatically creates an entity when storing an employee without entity_id', function (): void {
    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/employees', [
            'name' => 'Nasser El-Din',
            'operating_unit_id' => $this->unit->id,
            'job_title' => 'Operations Manager',
            'pay_type' => PayType::Monthly->value,
            'hire_date' => '2026-02-01',
            'status' => EmployeeStatus::Active->value,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.job_title', 'Operations Manager');

    $employeeId = $response->json('data.id');
    $employee = Employee::with('entity')->find($employeeId);

    expect($employee)->not->toBeNull();
    expect($employee->entity)->not->toBeNull();
    expect($employee->entity->name)->toBe('Nasser El-Din');
    expect($employee->entity->entity_type)->toBe(EntityType::Individual);
});

it('automatically creates an entity when storing a client without entity_id', function (): void {
    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/clients', [
            'name' => 'Sahara Trading Co.',
            'operating_unit_id' => $this->unit->id,
            'credit_limit' => 25000.00,
            'payment_terms_days' => 30,
            'status' => 'active',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.credit_limit', '25000.0000');

    $clientId = $response->json('data.id');
    $client = Client::with('entity')->find($clientId);

    expect($client)->not->toBeNull();
    expect($client->entity)->not->toBeNull();
    expect($client->entity->name)->toBe('Sahara Trading Co.');
    expect($client->entity->entity_type)->toBe(EntityType::Organization);
});

it('can split an employee entity via API', function (): void {
    $entity = Entity::create([
        'name' => 'Shared Entity Person',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
    ]);

    $employee = Employee::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Architect',
        'pay_type' => PayType::Monthly->value,
        'hire_date' => '2026-01-01',
        'status' => EmployeeStatus::Active->value,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/employees/{$employee->id}/split-entity", [
            'new_name' => 'Separated Employee Person',
        ]);

    $response->assertStatus(200);

    $employee->refresh();
    expect($employee->entity_id)->not->toBe($entity->id);
    expect($employee->entity->name)->toBe('Separated Employee Person');
});

it('can relink a client to another existing entity via API', function (): void {
    $entityA = Entity::create(['name' => 'Entity A', 'entity_type' => EntityType::Organization, 'is_active' => true]);
    $entityB = Entity::create(['name' => 'Entity B', 'entity_type' => EntityType::Organization, 'is_active' => true]);

    $client = Client::create([
        'entity_id' => $entityA->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 1000,
        'payment_terms_days' => 15,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/clients/{$client->id}/relink-entity", [
            'target_entity_id' => $entityB->id,
        ]);

    $response->assertStatus(200);

    $client->refresh();
    expect($client->entity_id)->toBe($entityB->id);
});
