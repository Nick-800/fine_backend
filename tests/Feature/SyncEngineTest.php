<?php

declare(strict_types=1);

use App\Enums\EntityType;
use App\Models\Company;
use App\Models\Entity;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\SyncConflict;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = Company::create([
        'name' => 'Test Enterprise',
        'default_currency' => 'LYD',
        'transfer_pricing_mode' => 'at_cost',
    ]);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Default Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom Store Unit',
        'unit_type' => 'store',
        'currency' => 'LYD',
        'status' => 'active',
    ]);

    $this->ownerRole = Role::create([
        'name' => 'Owner',
        'slug' => 'owner',
        'description' => 'Full Owner Access',
    ]);

    $this->user = User::create([
        'name' => 'Admin Manager',
        'email' => 'sync-admin@example.com',
        'password' => bcrypt('password123'),
        'must_change_password' => false,
        'is_active' => true,
    ]);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->ownerRole->id,
        'operating_unit_id' => null,
    ]);

    $this->headers = [
        'X-Operating-Unit-ID' => $this->unit->id,
    ];
});

it('pulls delta updates for syncable models modified after given timestamp', function (): void {
    $entity = Entity::create([
        'name' => 'Original Entity',
        'entity_type' => EntityType::Individual,
        'is_active' => true,
        'record_version' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/sync/pull?since='.urlencode(now()->subHour()->toIso8601String()));

    $response->assertOk()
        ->assertJsonStructure(['server_timestamp', 'changes'])
        ->assertJsonFragment(['name' => 'Original Entity']);
});

it('successfully processes non-conflicting operational outbox push', function (): void {
    $newUuid = (string) Str::uuid();

    $payload = [
        'device_id' => 'pos-terminal-01',
        'outbox' => [
            [
                'action_id' => 'act-001',
                'table' => 'entities',
                'record_id' => $newUuid,
                'operation' => 'create',
                'base_version' => 1,
                'data' => [
                    'name' => 'Offline Client Entity',
                    'entity_type' => 'organization',
                    'is_active' => true,
                ],
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/sync/push', $payload);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'synced');

    $this->assertDatabaseHas('entities', [
        'id' => $newUuid,
        'name' => 'Offline Client Entity',
    ]);
});

it('blocks offline outbox push for Tier 3A financial models', function (): void {
    $paymentReqUuid = (string) Str::uuid();

    $payload = [
        'device_id' => 'pos-terminal-01',
        'outbox' => [
            [
                'action_id' => 'act-002',
                'table' => 'payment_requests',
                'record_id' => $paymentReqUuid,
                'operation' => 'create',
                'base_version' => 1,
                'data' => [
                    'amount' => 5000,
                ],
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/sync/push', $payload);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'quarantined');

    $this->assertDatabaseHas('sync_conflicts', [
        'table_name' => 'payment_requests',
        'record_id' => $paymentReqUuid,
        'status' => 'quarantined',
    ]);
});

it('allows manager to resolve a quarantined sync conflict', function (): void {
    $conflict = SyncConflict::create([
        'operating_unit_id' => $this->unit->id,
        'device_id' => 'pos-terminal-01',
        'table_name' => 'entities',
        'record_id' => (string) Str::uuid(),
        'action' => 'create',
        'base_version' => 1,
        'server_version' => 1,
        'payload' => [
            'name' => 'Quarantined Corp',
            'entity_type' => 'organization',
            'is_active' => true,
        ],
        'conflict_reason' => 'Test conflict reason',
        'status' => 'quarantined',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson("/api/v1/sync/quarantined/{$conflict->id}/resolve", [
            'action' => 'override',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'resolved_override');

    $this->assertDatabaseHas('entities', [
        'name' => 'Quarantined Corp',
    ]);
});

it('successfully pushes and pulls work orders and inventory movements', function (): void {
    $woUuid = (string) Str::uuid();
    $imUuid1 = (string) Str::uuid();

    $payload = [
        'device_id' => 'pos-terminal-01',
        'outbox' => [
            [
                'action_id' => 'act-wo',
                'table' => 'work_orders',
                'record_id' => $woUuid,
                'operation' => 'create',
                'base_version' => 1,
                'data' => [
                    'productSku' => 'SKU-A',
                    'quantity' => 10,
                    'status' => 'open',
                    'createdAt' => now()->toIso8601String(),
                ],
            ],
            [
                'action_id' => 'act-im-1',
                'table' => 'inventory_movements',
                'record_id' => $imUuid1,
                'operation' => 'create',
                'base_version' => 1,
                'data' => [
                    'sku' => 'SKU-A',
                    'quantityDelta' => 10,
                    'reason' => 'production',
                    'referenceId' => $woUuid,
                    'createdAt' => now()->toIso8601String(),
                ],
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/sync/push', $payload);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'synced')
        ->assertJsonPath('results.1.status', 'synced');

    $this->assertDatabaseHas('work_orders', [
        'id' => $woUuid,
        'product_sku' => 'SKU-A',
        'quantity' => 10,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'id' => $imUuid1,
        'sku' => 'SKU-A',
        'quantity_delta' => 10,
        'reference_id' => $woUuid,
        'operating_unit_id' => $this->unit->id,
    ]);

    // Test Pulling
    $pullResponse = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/sync/pull?since='.urlencode(now()->subMinutes(5)->toIso8601String()));

    $pullResponse->assertOk()
        ->assertJsonStructure(['server_timestamp', 'changes'])
        ->assertJsonFragment(['productSku' => 'SKU-A'])
        ->assertJsonFragment(['referenceId' => $woUuid]);
});
