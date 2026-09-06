<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->seed(ChartOfAccountsSeeder::class);

    $blueprint = UnitBlueprint::create([
        'name' => 'Foam Blueprint', 'workflow_set' => ['production_batch' => []],
        'default_role_template' => [], 'default_inventory_config' => [],
    ]);

    $this->store = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store',
    ]);
    $this->otherUnit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id,
        'name' => 'Cutter', 'code' => 'CUT-01', 'unit_type' => 'manufactory',
    ]);

    $this->storeWarehouse = Warehouse::create([
        'operating_unit_id' => $this->store->id, 'name' => 'Store WH', 'code' => 'WH-S',
    ]);
    $this->otherWarehouse = Warehouse::create([
        'operating_unit_id' => $this->otherUnit->id, 'name' => 'Cutter WH', 'code' => 'WH-C',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Store Manager', 'slug' => 'store_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->store->id]);

    $entity = Entity::create(['entity_type' => 'organization', 'name' => 'Sahara Trading']);
    $this->client = Client::create([
        'entity_id' => $entity->id,
        'operating_unit_id' => $this->store->id,
        'credit_limit' => 100000,
        'current_balance' => 0,
    ]);

    $this->foamItem = InventoryItem::create([
        'name' => 'Foam Block Standard', 'sku' => 'FB-STD', 'item_type' => 'foam_block', 'unit_of_measure' => 'each',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->store->id]);

    $this->makeBlock = function (string $lotNumber, float $unitCost = 100, array $overrides = []): StockLot {
        $batch = ProductionBatch::create([
            'company_id' => $this->company->id,
            'operating_unit_id' => $this->store->id,
            'operation_number' => fake()->unique()->numberBetween(100, 99999),
            'formula_params' => [],
            'bun_width_m' => 1.0,
            'status' => 'closed',
            'next_sequence' => 1,
        ]);

        return StockLot::create(array_merge([
            'inventory_item_id' => $this->foamItem->id,
            'warehouse_id' => $this->storeWarehouse->id,
            'lot_number' => $lotNumber,
            'sequence_in_batch' => 1,
            'pressure' => 35,
            'production_batch_id' => $batch->id,
            'length_m' => 2.0,
            'width_m' => 1.0,
            'height_m' => 0.5,
            'volume_m3' => 1.0,
            'weight_kg' => 5.0,
            'quantity' => 1,
            'unit_cost' => $unitCost,
            'grade' => 'standard',
            'status' => 'available',
        ], $overrides));
    };
});

test('POS sells a specific foam block: line stores stock_lot_id and the lot is drawn', function () {
    $lot = ($this->makeBlock)('001-35-191', 100);

    $payload = [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_price' => 250,
        ]],
    ];

    $res = ($this->api)()->postJson('/api/v1/pos/sales', $payload)
        ->assertStatus(201)
        ->json();

    $line = SalesOrder::findOrFail($res['id'])->lines()->firstOrFail();
    expect($line->stock_lot_id)->toBe($lot->id);
    expect((float) $line->unit_cost_actual)->toBe(100.0);

    $lot->refresh();
    expect((float) $lot->quantity)->toBe(0.0);
    expect($lot->status)->toBe('consumed');

    $movement = InventoryMovement::where('stock_lot_id', $lot->id)
        ->where('reference_id', $res['id'])
        ->first();
    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity_delta)->toBe(-1.0);
    expect($movement->movement_type)->toBe('sale');
});

test('POS without stock_lot_id falls back to FIFO for foam blocks', function () {
    $lotA = ($this->makeBlock)('001-35-191', 80);
    $lotB = ($this->makeBlock)('002-35-191', 90);

    $res = ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]],
    ])->assertStatus(201)->json();

    $line = SalesOrder::findOrFail($res['id'])->lines()->firstOrFail();
    expect($line->stock_lot_id)->toBeNull();

    $lotA->refresh();
    $lotB->refresh();

    // FIFO: lotA is older (created first) so it is consumed; lotB untouched.
    expect($lotA->status)->toBe('consumed');
    expect($lotB->status)->toBe('available');
});

test('POS rejects stock_lot_id from a different operating unit', function () {
    $foreignLot = StockLot::create([
        'inventory_item_id' => $this->foamItem->id,
        'warehouse_id' => $this->otherWarehouse->id,
        'lot_number' => '003-35-191',
        'sequence_in_batch' => 1,
        'pressure' => 35,
        'quantity' => 1,
        'unit_cost' => 100,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $foreignLot->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]],
    ])->assertStatus(422)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'not in this operating unit'));
});

test('POS rejects a stock_lot_id whose inventory_item_id does not match the line', function () {
    $otherItem = InventoryItem::create([
        'name' => 'Sofa', 'sku' => 'SOFA-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ]);
    $wrongLot = StockLot::create([
        'inventory_item_id' => $otherItem->id,
        'warehouse_id' => $this->storeWarehouse->id,
        'lot_number' => 'SOFA-LOT-1',
        'quantity' => 5,
        'unit_cost' => 150,
        'status' => 'available',
    ]);

    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $wrongLot->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]],
    ])->assertStatus(422)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'does not carry the inventory item'));
});

test('POS rejects a stock_lot_id for an already-consumed block', function () {
    $lot = ($this->makeBlock)('004-35-191', 100);
    $lot->update(['status' => 'consumed', 'quantity' => 0]);

    ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]],
    ])->assertStatus(422)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'not available'));
});

test('available-foam-blocks lists only status=available, uncut, foam-block lots, smallest first', function () {
    $big = ($this->makeBlock)('005-35-191', 100, ['volume_m3' => 5.0]);
    $small = ($this->makeBlock)('006-35-191', 100, ['volume_m3' => 0.5]);
    $otherItem = ($this->makeBlock)('007-35-191', 100, ['volume_m3' => 1.0]);
    $otherItem->update(['inventory_item_id' => InventoryItem::create([
        'name' => 'Sofa', 'sku' => 'SOFA-1',
        'item_type' => 'furniture_finished_good', 'unit_of_measure' => 'each',
    ])->id]);
    $consumed = ($this->makeBlock)('008-35-191', 100, ['volume_m3' => 0.1]);
    $consumed->update(['status' => 'consumed', 'quantity' => 0]);

    $res = ($this->api)()->getJson('/api/v1/stock-lots/available-foam-blocks?inventory_item_id='.$this->foamItem->id)
        ->assertStatus(200)
        ->json();

    $ids = collect($res['data'])->pluck('id')->all();

    expect($ids)->toContain($small->id, $big->id)
        ->not->toContain($otherItem->id, $consumed->id);

    $volumes = collect($res['data'])->pluck('volume_m3')->map(fn ($v) => (float) $v)->all();
    $sorted = $volumes;
    sort($sorted);
    expect($volumes)->toBe($sorted);
});

test('POS show endpoint includes the picked lot_number on the line', function () {
    $lot = ($this->makeBlock)('009-35-191', 100);

    $res = ($this->api)()->postJson('/api/v1/pos/sales', [
        'order_number' => 'POS-'.fake()->unique()->numberBetween(1000, 9999),
        'payment_method' => 'cash',
        'items' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_price' => 200,
        ]],
    ])->assertStatus(201)->json();

    $show = ($this->api)()->getJson("/api/v1/pos/sales/{$res['id']}")
        ->assertStatus(200)
        ->json();

    $line = collect($show['lines'])->firstWhere('stock_lot_id', $lot->id);
    expect($line)->not->toBeNull();
    expect($line['stock_lot']['lot_number'])->toBe('009-35-191');
});

test('standard sales order can also pick a specific block', function () {
    $lot = ($this->makeBlock)('010-35-191', 100);

    $res = ($this->api)()->postJson('/api/v1/sales-orders', [
        'order_number' => 'SO-'.fake()->unique()->numberBetween(1000, 9999),
        'buyer_type' => 'client',
        'client_id' => $this->client->id,
        'lines' => [[
            'inventory_item_id' => $this->foamItem->id,
            'stock_lot_id' => $lot->id,
            'quantity' => 1,
            'unit_price' => 250,
        ]],
    ])->assertStatus(201)->json();

    ($this->api)()->postJson("/api/v1/sales-orders/{$res['id']}/submit")->assertStatus(200);
    ($this->api)()->postJson("/api/v1/sales-orders/{$res['id']}/fulfill")->assertStatus(200);

    $line = SalesOrder::findOrFail($res['id'])->lines()->firstOrFail();
    expect($line->stock_lot_id)->toBe($lot->id);
    expect((float) $line->unit_cost_actual)->toBe(100.0);

    $lot->refresh();
    expect($lot->status)->toBe('consumed');

    $invoice = ($this->api)()->getJson("/api/v1/sales-orders/{$res['id']}/invoice")
        ->assertStatus(200)
        ->json();
    $invLine = collect($invoice['lines'])->firstWhere('lot_number', '010-35-191');
    expect($invLine)->not->toBeNull();
});
