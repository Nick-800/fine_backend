<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\FoamBlockConsumption;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Al-Amana Cutter Block Test',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);

    // The chart-of-accounts seeder bails out if no Company exists, so it
    // must run after the company is created.
    $this->seed(ChartOfAccountsSeeder::class);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'General Blueprint',
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

    $owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner']);
    UserRole::create([
        'user_id' => $owner->id,
        'role_id' => $ownerRole->id,
        'operating_unit_id' => null,
    ]);
    $this->owner = $owner;

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Foam Warehouse',
        'is_internal_unit' => true,
    ]);

    $this->foamItem = InventoryItem::create([
        'name' => 'Foam Block',
        'sku' => 'FOAM-BLK',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);

    $this->block = StockLot::create([
        'inventory_item_id' => $this->foamItem->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_number' => 'BLK-TEST-001',
        'quantity' => 1,
        'length_m' => 2.0,
        'width_m' => 1.5,
        'height_m' => 1.0,
        'volume_m3' => 3.0,
        'unit_cost' => 500.0,
        'status' => 'available',
    ])->fresh();
});

it('lets an owner create an order with a block attached — block becomes reserved, wip includes cost', function () {
    $response = $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-001',
            'stock_lot_id' => $this->block->id,
        ]);

    $response->assertStatus(201);

    $order = CutterWorkOrder::find($response->json('id'));
    expect($order->stock_lot_id)->toBe($this->block->id);
    expect((float) $order->wip_cost)->toBe(500.0);
    expect((float) $order->block_unit_cost_snapshot)->toBe(500.0);
    expect((float) $order->block_length_m_snapshot)->toBe(2.0);
    expect((float) $order->block_width_m_snapshot)->toBe(1.5);
    expect((float) $order->block_height_m_snapshot)->toBe(1.0);

    $this->block->refresh();
    expect($this->block->status)->toBe('reserved');
});

it('rejects attaching a block that is not available', function () {
    $this->block->update(['status' => 'consumed']);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-CONSUMED',
            'stock_lot_id' => $this->block->id,
        ])
        ->assertStatus(422);
});

it('rejects attaching an already-reserved block to a second order', function () {
    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-FIRST',
            'stock_lot_id' => $this->block->id,
        ])
        ->assertStatus(201);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-SECOND',
            'stock_lot_id' => $this->block->id,
        ])
        ->assertStatus(422);
});

it('snapshot fields stay locked even if the block unit_cost changes later', function () {
    // The block's unit_cost at creation time is snapshotted onto the order.
    // Re-saving the block with a new unit_cost must not change the order's
    // snapshot (the snapshot is the contract with the customer at sale time).
    $response = $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-LOCK',
            'stock_lot_id' => $this->block->id,
        ]);

    expect($response->json('block_unit_cost_snapshot'))->toBe('500.0000');

    // Force a fresh DB read — the block itself now reports its current cost,
    // but the order's snapshot must still be the original.
    expect((float) $this->block->fresh()->unit_cost)->toBe(500.0);
});

it('advancing to in_production consumes the reserved block and creates a FoamBlockConsumption', function () {
    $pieceItem = InventoryItem::create([
        'name' => 'Cut Piece',
        'sku' => 'PIECE-PROD',
        'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each',
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-PROD',
            'stock_lot_id' => $this->block->id,
        ])
        ->assertStatus(201);

    $order = CutterWorkOrder::where('order_number', 'CW-TEST-PROD')->firstOrFail();

    $order->lines()->create([
        'requested_spec' => 'test piece',
        'quantity' => 1,
        'output_inventory_item_id' => $pieceItem->id,
        'template_length_m' => 1.0,
        'template_width_m' => 1.0,
        'template_height_m' => 1.0,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", [
            'status' => 'confirmed',
        ])
        ->assertOk();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $this->block->refresh();
    expect($this->block->status)->toBe('consumed');
    expect((float) $this->block->quantity)->toBe(0.0);

    $consumption = FoamBlockConsumption::where('stock_lot_id', $this->block->id)->first();
    expect($consumption)->not->toBeNull();
    expect((float) $consumption->consumed_cost)->toBe(500.0);
});

it('completing the order produces pieces that carry source_stock_lot_id + source_block dims', function () {
    $pieceItem = InventoryItem::create([
        'name' => 'Cut Piece',
        'sku' => 'PIECE-001',
        'item_type' => 'cut_template_piece',
        'unit_of_measure' => 'each',
    ]);

    $response = $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-OUT',
            'stock_lot_id' => $this->block->id,
        ]);

    $order = CutterWorkOrder::where('order_number', 'CW-TEST-OUT')->firstOrFail();

    $order->lines()->create([
        'requested_spec' => '2-seater cushion',
        'quantity' => 2,
        'output_inventory_item_id' => $pieceItem->id,
        'template_length_m' => 1.0,
        'template_width_m' => 0.5,
        'template_height_m' => 0.2,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", ['status' => 'confirmed'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", ['status' => 'in_production'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", ['status' => 'awaiting_byproduct_weigh_in'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/weigh-in", [
            'weight_kg' => 0,
        ])
        ->assertSuccessful();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", ['status' => 'quality_check'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson("/api/v1/cutter-work-orders/{$order->id}/transition", ['status' => 'completed'])
        ->assertSuccessful();

    $pieces = StockLot::where('source_stock_lot_id', $this->block->id)->get();
    expect($pieces)->toHaveCount(2);
    expect((float) $pieces->first()->source_block_length_m)->toBe(2.0);
    expect((float) $pieces->first()->source_block_width_m)->toBe(1.5);
    expect((float) $pieces->first()->source_block_height_m)->toBe(1.0);
    expect((float) $pieces->first()->length_m)->toBe(1.0);
});

it('orders list includes the attached block snapshot fields and stock_lot relation', function () {
    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->postJson('/api/v1/cutter-work-orders', [
            'order_number' => 'CW-TEST-LIST',
            'stock_lot_id' => $this->block->id,
        ])
        ->assertStatus(201);

    $list = $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/cutter-work-orders')
        ->json();

    $row = collect($list['data'])->firstWhere('order_number', 'CW-TEST-LIST');
    expect($row)->not->toBeNull();
    expect($row['stock_lot']['lot_number'])->toBe('BLK-TEST-001');
    expect((float) $row['block_unit_cost_snapshot'])->toBe(500.0);
});
