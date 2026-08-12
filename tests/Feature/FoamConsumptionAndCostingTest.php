<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\TankStock;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);

    // Closing a batch posts DR Finished Goods / CR WIP, so the accounts have to
    // exist. A close that silently skipped posting would leave inventory value
    // with no ledger counterpart.
    $this->seed(ChartOfAccountsSeeder::class);
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
    $this->polyol = InventoryItem::create([
        'name' => 'Polyol 15%', 'sku' => 'CHEM-POLY-15', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $this->tdi = InventoryItem::create([
        'name' => 'TDI', 'sku' => 'CHEM-TDI', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);
    $this->scrapItem = InventoryItem::create([
        'name' => 'Foam Scrap Fill', 'sku' => 'SCRAP-FILL', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'm3',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->fillTank = fn (InventoryItem $item, float $qty, float $cost) => TankStock::create([
        'chemical_inventory_item_id' => $item->id,
        'operating_unit_id' => $this->unit->id,
        'quantity_on_hand' => $qty,
        'weighted_avg_unit_cost' => $cost,
    ]);

    $this->batch = fn (string $status = 'running') => ProductionBatch::create([
        'operating_unit_id' => $this->unit->id,
        'operation_number' => 191,
        'bun_width_m' => 2.4,
        'status' => $status,
    ]);
});

test('a consumption report draws from tanks and snapshots unit cost', function () {
    ($this->fillTank)($this->polyol, 2000, 3.0);
    ($this->fillTank)($this->tdi, 2000, 5.0);
    $batch = ($this->batch)();

    // 899 kg polyol @ 3 + 1129 kg TDI @ 5 = 2697 + 5645 = 8342
    $response = ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [
            ['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => $this->tdi->id, 'quantity_consumed' => 1129],
        ],
    ])->assertStatus(201);

    expect((float) $response->json('material_cost'))->toBe(8342.0);

    expect((float) TankStock::where('chemical_inventory_item_id', $this->polyol->id)->value('quantity_on_hand'))->toBe(1101.0)
        ->and((float) TankStock::where('chemical_inventory_item_id', $this->tdi->id)->value('quantity_on_hand'))->toBe(871.0)
        ->and((float) $batch->fresh()->material_cost)->toBe(8342.0);
});

test('a later refill does not move an already-recorded consumption cost', function () {
    // FOAM-04: the snapshot is the point — the tank average moves, the batch does not.
    ($this->fillTank)($this->polyol, 1000, 3.0);
    $batch = ($this->batch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 100]],
    ])->assertStatus(201);

    ($this->api)()->postJson('/api/v1/tank-stocks/refill', [
        'chemical_inventory_item_id' => $this->polyol->id,
        'refill_quantity' => 900,
        'refill_unit_cost' => 20.0,
    ])->assertStatus(201);

    // Tank average has moved, the batch's recorded cost has not.
    expect((float) $batch->fresh()->material_cost)->toBe(300.0);
});

test('a run is blocked when a tank cannot cover it', function () {
    ($this->fillTank)($this->polyol, 50, 3.0);
    $batch = ($this->batch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 899]],
    ])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_TANK_STOCK');

    // FOAM-02: nothing drawn, no report written — the whole thing rolls back.
    expect((float) TankStock::where('chemical_inventory_item_id', $this->polyol->id)->value('quantity_on_hand'))->toBe(50.0)
        ->and((float) $batch->fresh()->material_cost)->toBe(0.0);
});

test('a shortfall on any one chemical draws none of the others', function () {
    ($this->fillTank)($this->polyol, 2000, 3.0);
    ($this->fillTank)($this->tdi, 10, 5.0); // short

    $batch = ($this->batch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [
            ['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => $this->tdi->id, 'quantity_consumed' => 1129],
        ],
    ])->assertStatus(422);

    // The plentiful tank must be untouched, not drained before the shortfall was found.
    expect((float) TankStock::where('chemical_inventory_item_id', $this->polyol->id)->value('quantity_on_hand'))->toBe(2000.0);
});

test('a zero-quantity line is recorded without drawing stock', function () {
    // The production report lists optional inputs; a 0 is data, not a missing value.
    ($this->fillTank)($this->polyol, 1000, 3.0);
    $batch = ($this->batch)();

    $response = ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [
            ['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 0],
            ['chemical_inventory_item_id' => $this->tdi->id, 'quantity_consumed' => 0],
        ],
    ])->assertStatus(201);

    expect($response->json('report.lines'))->toHaveCount(2)
        ->and((float) TankStock::where('chemical_inventory_item_id', $this->polyol->id)->value('quantity_on_hand'))->toBe(1000.0);
});

test('material cost is apportioned across blocks by volume on close', function () {
    ($this->fillTank)($this->polyol, 5000, 2.0);
    $batch = ($this->batch)();

    // 1000 kg @ 2.0 = 2000 material cost
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 1000]],
    ])->assertStatus(201);

    foreach (['consumed', 'curing', 'ready_for_grading'] as $status) {
        ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => $status])
            ->assertStatus(200);
    }

    // Two blocks: 3.84 m3 and 1.92 m3 -> a 2:1 split of the cost.
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ['kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id],
            ['kind' => 'block', 'count' => 1, 'length_m' => 1.0, 'height_m' => 0.8, 'pressure' => 35,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id],
        ],
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'graded'])->assertStatus(200);
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'closed'])->assertStatus(200);

    $lots = StockLot::where('production_batch_id', $batch->id)->orderBy('sequence_in_batch')->get();

    expect((float) $lots[0]->unit_cost)->toBe(1333.3333)
        ->and((float) $lots[1]->unit_cost)->toBe(666.6667)
        // The apportioned costs must sum back to the batch total exactly.
        ->and(round((float) $lots[0]->unit_cost + (float) $lots[1]->unit_cost, 4))->toBe(2000.0);
});

test('scrap volume is excluded from cost apportionment', function () {
    ($this->fillTank)($this->polyol, 5000, 2.0);
    $batch = ($this->batch)();

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/consumption-report", [
        'lines' => [['chemical_inventory_item_id' => $this->polyol->id, 'quantity_consumed' => 1000]],
    ])->assertStatus(201);

    foreach (['consumed', 'curing', 'ready_for_grading'] as $status) {
        ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => $status]);
    }

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/blocks", [
        'groups' => [
            ['kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
                'inventory_item_id' => $this->blockItem->id, 'warehouse_id' => $this->warehouse->id],
            ['kind' => 'scrap', 'count' => 1, 'length_m' => 2.0, 'height_m' => 1.0,
                'inventory_item_id' => $this->scrapItem->id, 'warehouse_id' => $this->warehouse->id],
        ],
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'graded']);
    ($this->api)()->postJson("/api/v1/production-batches/{$batch->id}/transition", ['status' => 'closed']);

    // The single block carries the whole 2000; scrap is in stock but at zero.
    expect((float) $batch->blocks()->first()->unit_cost)->toBe(2000.0)
        ->and((float) $batch->scrapLots()->first()->unit_cost)->toBe(0.0);
});
