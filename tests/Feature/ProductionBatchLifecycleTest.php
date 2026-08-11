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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
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
        'name' => 'Foam Block', 'sku' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->batch = fn (string $status = 'planned') => ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'status' => $status,
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
