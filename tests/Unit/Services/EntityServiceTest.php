<?php

declare(strict_types=1);

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\UnitBlueprint;
use App\Services\EntityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(EntityService::class);

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
});

it('creates an entity and role for an individual domain model', function (): void {
    $entity = $this->service->createEntityForDomainModel(
        roleType: EntityRoleType::Employee,
        attributes: [
            'name' => 'Sara Ahmed',
            'entity_type' => EntityType::Individual,
        ]
    );

    expect($entity)->not->toBeNull();
    expect($entity->name)->toBe('Sara Ahmed');
    expect($entity->entity_type)->toBe(EntityType::Individual);
    expect($entity->roles)->toHaveCount(1);
    expect($entity->roles->first()->role_type)->toBe(EntityRoleType::Employee);
});

it('ensures an existing entity receives a new role without duplication', function (): void {
    $entity = Entity::create([
        'name' => 'Global Logistics LLC',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $this->service->ensureEntityRole($entity, EntityRoleType::Client);
    $this->service->ensureEntityRole($entity, EntityRoleType::Client);

    $entity->refresh();
    expect($entity->roles)->toHaveCount(1);
    expect($entity->roles->first()->role_type)->toBe(EntityRoleType::Client);
});

it('splits a domain model off into a new standalone entity', function (): void {
    $originalEntity = Entity::create([
        'name' => 'Shared Entity',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $client = Client::create([
        'entity_id' => $originalEntity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 10000,
        'payment_terms_days' => 30,
        'status' => 'active',
    ]);

    $newEntity = $this->service->splitEntity($client, EntityRoleType::Client, 'Standalone Client Entity');

    $client->refresh();
    expect($client->entity_id)->toBe($newEntity->id);
    expect($client->entity_id)->not->toBe($originalEntity->id);
    expect($newEntity->name)->toBe('Standalone Client Entity');
    expect($newEntity->roles->first()->role_type)->toBe(EntityRoleType::Client);
});

it('relinks a domain model to another existing entity', function (): void {
    $entityA = Entity::create(['name' => 'Entity A', 'entity_type' => EntityType::Individual, 'is_active' => true]);
    $entityB = Entity::create(['name' => 'Entity B', 'entity_type' => EntityType::Individual, 'is_active' => true]);

    $employee = Employee::create([
        'entity_id' => $entityA->id,
        'operating_unit_id' => $this->unit->id,
        'job_title' => 'Engineer',
        'pay_type' => 'monthly',
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->service->relinkEntity($employee, $entityB->id, EntityRoleType::Employee);

    $employee->refresh();
    expect($employee->entity_id)->toBe($entityB->id);
    expect($entityB->roles)->toHaveCount(1);
    expect($entityB->roles->first()->role_type)->toBe(EntityRoleType::Employee);
});
