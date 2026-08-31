<?php

declare(strict_types=1);

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Models\Client;
use App\Models\Company;
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

it('links a domain model to a pre-existing entity', function (): void {
    $entity = Entity::create([
        'name' => 'Pre-existing Entity',
        'entity_type' => EntityType::Organization,
        'is_active' => true,
    ]);

    $client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->unit->id,
        'credit_limit' => 10000,
        'payment_terms_days' => 30,
        'status' => 'active',
    ]);

    expect($client->entity_id)->toBe($entity->id);
    $this->service->ensureEntityRole($client->entity, EntityRoleType::Client, $this->unit->id);
    expect($client->entity->fresh()->roles->first()->role_type)->toBe(EntityRoleType::Client);
});
