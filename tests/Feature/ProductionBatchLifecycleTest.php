<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsTestSeeder::class);
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Plant', 'code' => 'FOAM-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'code' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->batch = fn (string $status = 'planned', float $materialCost = 1000.0) => ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'status' => $status,
        'material_cost' => $materialCost,
    ]);

    $this->moveTo = function (ProductionBatch $batch, string $status) {
        return ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => $status]);
    };
});

test('a batch walks the full lifecycle in order', function () {
    $batch = ($this->batch)();

    foreach (['configured', 'running', 'consumed', 'curing', 'ready_for_grading'] as $status) {
        ($this->moveTo)($batch, $status)->assertStatus(200)->assertJsonPath('status', $status);
    }

    // Grading needs output, so register a block before the last two steps.
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(201);

    ($this->moveTo)($batch, 'graded')->assertStatus(200);
    ($this->moveTo)($batch, 'closed')->assertStatus(200)->assertJsonPath('status', 'closed');
});

test('skipping a state is rejected', function () {
    $batch = ($this->batch)();

    ($this->moveTo)($batch, 'running')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    expect($batch->fresh()->status->value)->toBe('planned');
});

test('moving backwards is rejected', function () {
    $batch = ($this->batch)('curing');

    ($this->moveTo)($batch, 'running')->assertStatus(422);
});

test('a closed batch cannot change state', function () {
    $batch = ($this->batch)('closed');

    ($this->moveTo)($batch, 'graded')
        ->assertStatus(422)
        ->assertJsonFragment(['code' => 'INVALID_STATE_TRANSITION']);
});

test('a batch with no blocks cannot be graded', function () {
    // FOAM-05: nothing to grade means the run produced nothing.
    $batch = ($this->batch)('ready_for_grading');

    ($this->moveTo)($batch, 'graded')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('a batch with an unmeasured block cannot be graded', function () {
    $batch = ($this->batch)('ready_for_grading');

    // Bypass the API, which requires pressure, to prove the guard itself holds.
    StockLot::create([
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'production_batch_id' => $batch->id,
        'lot_number' => 'UNGRADED-1',
        'quantity' => 1,
        'unit_cost' => 0,
        'status' => 'available',
    ]);

    ($this->moveTo)($batch, 'graded')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('blocks cannot be registered before the batch is ready for grading', function () {
    $batch = ($this->batch)('running');

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    expect(StockLot::where('production_batch_id', $batch->id)->count())->toBe(0);
});

test('registering blocks posts a production output movement per block', function () {
    // INV-06: stock never appears without a movement behind it.
    $batch = ($this->batch)('ready_for_grading');

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 3, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(201);

    $movements = ($this->api)()
        ->getJson("/api/v1/inventory-movements/for-document/ProductionBatch/{$batch->id}")
        ->assertStatus(200)
        ->json();

    expect($movements)->toHaveCount(3)
        ->and(collect($movements)->pluck('movement_type')->unique()->all())->toBe(['production_output']);
});

test('closing with material_cost 0 is rejected and status stays graded', function () {
    $batch = ($this->batch)('ready_for_grading', 0.0);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(201);

    ($this->moveTo)($batch, 'graded')->assertStatus(200);

    ($this->moveTo)($batch, 'closed')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('message', "Operation {$batch->operation_number} cannot close without a material cost — enter it first so its blocks carry value.");

    expect($batch->fresh()->status->value)->toBe('graded');
});

test('closing with material_cost 1000 sets non-zero unit_cost on blocks summing to 1000 and posts one journal entry', function () {
    $batch = ($this->batch)('ready_for_grading', 1000.0);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            [
                'kind' => 'block', 'count' => 2, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
            ],
        ],
    ])->assertStatus(201);

    ($this->moveTo)($batch, 'graded')->assertStatus(200);
    ($this->moveTo)($batch, 'closed')->assertStatus(200)->assertJsonPath('status', 'closed');

    $blocks = $batch->fresh()->blocks;
    expect($blocks)->toHaveCount(2)
        ->and((float) $blocks[0]->unit_cost)->toBeGreaterThan(0)
        ->and((float) $blocks[1]->unit_cost)->toBeGreaterThan(0)
        ->and((float) $blocks->sum('unit_cost'))->toBe(1000.0);

    $entries = ($this->api)()
        ->getJson("/api/v1/journal-entries/for-document/ProductionBatch/{$batch->id}")
        ->assertStatus(200)
        ->json();

    expect($entries)->toHaveCount(1);
});

test('PUT with status closed goes through the same rules', function () {
    $batch = ($this->batch)('ready_for_grading', 0.0);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id,
        ]],
    ])->assertStatus(201);

    ($this->moveTo)($batch, 'graded')->assertStatus(200);

    // Rejected at zero cost
    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'status' => 'closed',
        'record_version' => $batch->fresh()->record_version,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('message', "Operation {$batch->operation_number} cannot close without a material cost — enter it first so its blocks carry value.");

    expect($batch->fresh()->status->value)->toBe('graded');

    // At positive cost the journal is posted and status closes
    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'status' => 'closed',
        'material_cost' => 1000.0,
        'record_version' => $batch->fresh()->record_version,
    ])
        ->assertStatus(200)
        ->assertJsonPath('status', 'closed');

    expect($batch->fresh()->status->value)->toBe('closed');

    $entries = ($this->api)()
        ->getJson("/api/v1/journal-entries/for-document/ProductionBatch/{$batch->id}")
        ->assertStatus(200)
        ->json();

    expect($entries)->toHaveCount(1);
});

test('PUT with only material_cost still works', function () {
    $batch = ($this->batch)('graded', 0.0);

    ($this->api)()->putJson("/api/v1/production-batches/{$batch->id}", [
        'material_cost' => 1500.0,
        'record_version' => $batch->record_version,
    ])
        ->assertStatus(200)
        ->assertJsonPath('material_cost', '1500.0000');

    expect((float) $batch->fresh()->material_cost)->toBe(1500.0)
        ->and($batch->fresh()->status->value)->toBe('graded');
});
