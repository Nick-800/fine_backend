<?php

declare(strict_types=1);

use App\Models\ByproductYield;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderLine;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
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
        'name' => 'Cutter Plant', 'code' => 'CUT-01', 'unit_type' => 'manufactory',
    ]);
    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id, 'name' => 'WH', 'code' => 'WH-1',
    ]);
    $this->user = User::factory()->create(['must_change_password' => false]);
    $role = Role::create(['name' => 'Cutter Manager', 'slug' => 'cutter_manager']);
    UserRole::create(['user_id' => $this->user->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->blockItem = InventoryItem::create([
        'name' => 'Foam Block', 'code' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);
    $this->pieceItem = InventoryItem::create([
        'name' => 'Cut Template Piece', 'code' => 'PIECE-1', 'item_type' => 'cut_template_piece', 'unit_of_measure' => 'each',
    ]);
    $this->fillItem = InventoryItem::create([
        'name' => 'Byproduct Fill', 'code' => 'FILL-1', 'item_type' => 'byproduct_fill', 'unit_of_measure' => 'kg',
    ]);

    $this->api = fn () => $this->actingAs($this->user)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unit->id]);

    $this->block = fn (string $lot, float $l, float $w, float $h, float $cost) => StockLot::create([
        'inventory_item_id' => $this->blockItem->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => $lot,
        'quantity' => 1,
        'length_m' => $l, 'width_m' => $w, 'height_m' => $h,
        'unit_cost' => $cost,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    // An order with one templated line, ready for block selection.
    $this->orderWithLine = function (float $tl = 1.0, float $tw = 1.0, float $th = 1.0, int $qty = 1) {
        $order = ($this->api)()->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CWO-'.fake()->unique()->numberBetween(1000, 9999),
        ])->json();

        $line = ($this->api)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
            'requested_spec' => 'Round pillow insert, radius 8cm',
            'quantity' => $qty,
            'output_inventory_item_id' => $this->pieceItem->id,
        ])->json();

        ($this->api)()->putJson("/api/v1/cutter-work-order-lines/{$line['id']}/assign-template", [
            'template_length_m' => $tl,
            'template_width_m' => $tw,
            'template_height_m' => $th,
        ])->assertStatus(200);

        ($this->api)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'confirmed'])
            ->assertStatus(200);

        return [$order['id'], $line['id']];
    };
});

test('template volume is derived from dimensions, never entered', function () {
    [, $lineId] = ($this->orderWithLine)(2.0, 0.5, 0.4);

    $line = ($this->api)()->getJson("/api/v1/cutter-work-order-lines/{$lineId}/available-blocks");

    // 2.0 x 0.5 x 0.4 = 0.4
    expect((float) CutterWorkOrderLine::find($lineId)->template_volume_m3)->toBe(0.4);
});

test('production cannot start on a line with no template', function () {
    // CUT-01
    $order = ($this->api)()->postJson('/api/v1/cutter-work-orders', ['order_number' => 'CWO-NO-TPL'])->json();

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/lines", [
        'requested_spec' => 'Something vague',
        'quantity' => 1,
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'confirmed'])
        ->assertStatus(200);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$order['id']}/transition", ['status' => 'in_production'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('only blocks big enough for the template are offered', function () {
    [, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0); // needs 1.0 m3

    ($this->block)('SMALL', 0.5, 0.5, 0.5, 100);  // 0.125 — too small
    ($this->block)('EXACT', 1.0, 1.0, 1.0, 300);  // 1.0
    ($this->block)('LARGE', 2.0, 1.0, 1.0, 600);  // 2.0

    $blocks = ($this->api)()
        ->getJson("/api/v1/cutter-work-order-lines/{$lineId}/available-blocks")
        ->assertStatus(200)
        ->json('data');

    $lots = collect($blocks)->pluck('lot_number')->all();

    // Smallest adequate first: cutting a big block for a small template wastes
    // the difference.
    expect($lots)->toBe(['EXACT', 'LARGE']);
});

test('a block too small for the template is refused', function () {
    [, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $small = ($this->block)('SMALL', 0.5, 0.5, 0.5, 100);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", [
        'stock_lot_id' => $small->id,
    ])->assertStatus(500); // InvalidArgumentException — volume short

    expect($small->fresh()->status)->toBe('available');
});

test('selecting a block moves its whole cost into the order', function () {
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600); // 2.0 m3 @ 600

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", [
        'stock_lot_id' => $block->id,
    ])->assertStatus(201);

    $block->refresh();
    $order = CutterWorkOrder::find($orderId);

    // Half the block is used, so half its cost is the template's; the rest rides
    // with the offcut. Both stay inside the order.
    expect($block->status)->toBe('consumed')
        ->and((float) $order->wip_cost)->toBe(600.0);

    $consumption = $order->consumptions()->first();
    expect((float) $consumption->consumed_cost)->toBe(300.0)
        ->and((float) $consumption->remainder_cost)->toBe(300.0)
        ->and($consumption->consumption_type)->toBe('partial')
        // Nothing may go missing between the block and its outputs.
        ->and($consumption->totalCost())->toBe(600.0);
});

test('a block matching the template is treated as fully consumed', function () {
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B-EXACT', 1.0, 1.0, 1.0, 400);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", [
        'stock_lot_id' => $block->id,
    ])->assertStatus(201);

    $consumption = CutterWorkOrder::find($orderId)->consumptions()->first();

    expect($consumption->consumption_type)->toBe('full')
        ->and((float) $consumption->consumed_cost)->toBe(400.0)
        ->and((float) $consumption->remainder_cost)->toBe(0.0);
});

test('the same block cannot be cut twice', function () {
    [, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", [
        'stock_lot_id' => $block->id,
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", [
        'stock_lot_id' => $block->id,
    ])->assertStatus(500); // already consumed
});

test('an order cannot reach quality check without a weigh-in', function () {
    // CUT-03: the gate exists because this is the step everyone would skip.
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", ['stock_lot_id' => $block->id]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'in_production']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'awaiting_byproduct_weigh_in']);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

test('a zero weigh-in is a valid answer and opens the gate', function () {
    // CUT-04: zero says someone looked; a missing record says nobody did.
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 1.0, 1.0, 1.0, 400);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", ['stock_lot_id' => $block->id]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'in_production']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'awaiting_byproduct_weigh_in']);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", ['weight_kg' => 0])
        ->assertStatus(201);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check'])
        ->assertStatus(200);

    expect(ByproductYield::where('cutter_work_order_id', $orderId)->count())->toBe(1);
});

test('a full order run conserves the block cost across its outputs', function () {
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600); // half used, half offcut

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", ['stock_lot_id' => $block->id]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'in_production']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'awaiting_byproduct_weigh_in']);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", [
        'weight_kg' => 30,
        'byproduct_inventory_item_id' => $this->fillItem->id,
        'warehouse_id' => $this->warehouse->id,
    ])->assertStatus(201);

    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed'])
        ->assertStatus(200);

    $piece = StockLot::where('inventory_item_id', $this->pieceItem->id)->first();
    $fill = StockLot::where('inventory_item_id', $this->fillItem->id)->first();

    // CUT-05: the piece is stocked at template dimensions, not the requested shape.
    expect((float) $piece->length_m)->toBe(1.0)
        ->and((float) $piece->unit_cost)->toBe(300.0)
        ->and((float) $fill->quantity)->toBe(30.0)
        // 300 of offcut cost spread over 30kg
        ->and((float) $fill->unit_cost)->toBe(10.0);

    // The block's 600 is fully represented by its outputs — nothing evaporated.
    expect(round((float) $piece->unit_cost + ((float) $fill->unit_cost * 30), 4))->toBe(600.0);
});

test('completing an order produces the three stock movements', function () {
    // CUT-09
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", ['stock_lot_id' => $block->id]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'in_production']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'awaiting_byproduct_weigh_in']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", [
        'weight_kg' => 30,
        'byproduct_inventory_item_id' => $this->fillItem->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed']);

    $movements = ($this->api)()
        ->getJson("/api/v1/inventory-movements/for-document/CutterWorkOrder/{$orderId}")
        ->json();

    $types = collect($movements)->pluck('movement_type')->sort()->values()->all();

    expect($types)->toBe(['byproduct_yield', 'consumption', 'production_output']);
});

test('the ledger balances across a completed cutter order', function () {
    [$orderId, $lineId] = ($this->orderWithLine)(1.0, 1.0, 1.0);
    $block = ($this->block)('B1', 2.0, 1.0, 1.0, 600);

    ($this->api)()->postJson("/api/v1/cutter-work-order-lines/{$lineId}/select-block", ['stock_lot_id' => $block->id]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'in_production']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'awaiting_byproduct_weigh_in']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/weigh-in", [
        'weight_kg' => 30,
        'byproduct_inventory_item_id' => $this->fillItem->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'quality_check']);
    ($this->api)()->postJson("/api/v1/cutter-work-orders/{$orderId}/transition", ['status' => 'completed']);

    $tb = app(AccountingService::class)->trialBalance();

    expect($tb['balanced'])->toBeTrue();

    $byCode = collect($tb['rows'])->keyBy('account_code');

    // Value left foam blocks, passed through WIP, and landed in cut pieces and fill.
    expect((float) $byCode['1131']['credit'])->toBe(600.0)
        ->and((float) $byCode['1122']['balance'])->toBe(0.0)
        ->and((float) $byCode['1132']['debit'])->toBe(300.0)
        ->and((float) $byCode['1133']['debit'])->toBe(300.0);
});

test('an internal order needs no client', function () {
    $order = ($this->api)()->postJson('/api/v1/cutter-work-orders', [
        'order_number' => 'CWO-INTERNAL-1',
    ])->assertStatus(201)->json();

    expect(CutterWorkOrder::find($order['id'])->isInternal())->toBeTrue();
});
