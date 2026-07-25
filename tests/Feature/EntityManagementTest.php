<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\EntityType;
use App\Enums\PayType;
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

it('can create an entity without a user account', function (): void {
    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/entities', [
            'name' => 'Acme Supplies Co.',
            'entity_type' => EntityType::Organization->value,
            'tax_number' => 'TAX-998877',
            'contact' => [
                'contact_name' => 'John Doe',
                'email' => 'acme@example.com',
                'phone' => '+218912345678',
                'address' => 'Tripoli Center',
                'city' => 'Tripoli',
                'country' => 'LY',
            ],
            'roles' => [
                [
                    'role_type' => 'client',
                    'operating_unit_id' => $this->unit->id,
                ],
                [
                    'role_type' => 'external_employer',
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Acme Supplies Co.')
        ->assertJsonPath('data.user_id', null)
        ->assertJsonPath('data.entity_type', 'organization');

    $entityId = $response->json('data.id');
    $entity = Entity::with(['roles', 'contacts', 'primaryContact'])->find($entityId);

    expect($entity)->not->toBeNull();
    expect($entity->user_id)->toBeNull();
    expect($entity->roles)->toHaveCount(2);
    expect($entity->primaryContact->email)->toBe('acme@example.com');
});

it('can create an employee linked to an entity without a user account', function (): void {
    $entity = Entity::create([
        'name' => 'Ali Omar',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/employees', [
            'entity_id' => $entity->id,
            'operating_unit_id' => $this->unit->id,
            'job_title' => 'Foam Mold Technician',
            'pay_type' => PayType::Monthly->value,
            'hire_date' => '2026-01-15',
            'status' => EmployeeStatus::Active->value,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.entity_id', $entity->id)
        ->assertJsonPath('data.job_title', 'Foam Mold Technician');

    $employee = Employee::where('entity_id', $entity->id)->first();
    expect($employee)->not->toBeNull();
    expect($employee->employer_entity_id)->toBeNull(); // Internal direct hire
});

it('can create a client linked to an entity without a user account', function (): void {
    $entity = Entity::create([
        'name' => 'Benghazi Trading Corp',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/clients', [
            'entity_id' => $entity->id,
            'operating_unit_id' => $this->unit->id,
            'credit_limit' => 50000.00,
            'payment_terms_days' => 45,
            'status' => 'active',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.entity_id', $entity->id)
        ->assertJsonPath('data.credit_limit', '50000.0000');
});

it('can provision a user account for an existing entity on demand', function (): void {
    $entity = Entity::create([
        'name' => 'Fatima Hassan',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
    ]);

    $entity->contacts()->create([
        'contact_name' => 'Fatima Hassan',
        'email' => 'fatima@example.com',
        'is_primary' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/entities/{$entity->id}/provision-user");

    $response->assertStatus(201)
        ->assertJsonPath('data.id', $entity->id);

    $entity->refresh();
    expect($entity->user_id)->not->toBeNull();

    $user = User::find($entity->user_id);
    expect($user)->not->toBeNull();
    expect($user->email)->toBe('fatima@example.com');
    expect($user->must_change_password)->toBeTrue();
});

it('allows admin to specify a custom temporary password when provisioning', function (): void {
    $entity = Entity::create([
        'name' => 'Tariq Mansour',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/entities/{$entity->id}/provision-user", [
            'email' => 'tariq@example.com',
            'password' => 'TempPass123!',
        ]);

    $response->assertStatus(201);

    $entity->refresh();
    $user = User::find($entity->user_id);

    expect($user)->not->toBeNull();
    expect($user->email)->toBe('tariq@example.com');
    expect(Hash::check('TempPass123!', $user->password))->toBeTrue();
    expect($user->must_change_password)->toBeTrue();
});
