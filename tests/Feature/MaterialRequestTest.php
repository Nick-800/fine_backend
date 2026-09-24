<?php

declare(strict_types=1);

use App\Enums\CutterWorkOrderStatus;
use App\Enums\ProductionBatchStatus;
use App\Enums\ProductionOrderStatus;
use App\Models\Bom;
use App\Models\BomComponentLine;
use App\Models\Company;
use App\Models\CutterWorkOrder;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\MaterialResolutionService;
use Database\Seeders\ChartOfAccountsTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Fine Material Request Test',
        'default_currency' => 'LYD',
        'overhead_absorption_enabled' => false,
        'transfer_pricing_mode' => 'at_cost',
        'timezone' => 'Africa/Tripoli',
    ]);
    $this->seed(ChartOfAccountsTestSeeder::class);

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
        'name' => 'Main Warehouse',
        'is_internal_unit' => true,
    ]);

    $this->rawMaterial = InventoryItem::create([
        'name' => 'Polyol Resin',
        'sku' => 'RAW-POL-001',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);

    $this->foamBlockItem = InventoryItem::create([
        'name' => 'Foam Block',
        'sku' => 'FOAM-BLK-001',
        'item_type' => 'foam_block',
        'unit_of_measure' => 'm3',
    ]);
});

function makeBomWithLine(ProductionOrderTestSetup $ctx, array $lineOverrides = []): ProductionOrder
{
    $product = InventoryItem::create([
        'name' => 'Sofa',
        'sku' => 'PROD-SOFA',
        'item_type' => 'furniture_finished_good',
        'unit_of_measure' => 'each',
    ]);

    $productRow = Product::create([
        'operating_unit_id' => $ctx->unit->id,
        'inventory_item_id' => $product->id,
        'name' => 'Sofa',
        'sku' => 'SOFA-001',
        'markup_factor' => 1.2,
    ]);

    $bom = Bom::create(['product_id' => $productRow->id, 'version' => 1, 'is_active' => true]);

    BomComponentLine::create(array_merge([
        'bom_id' => $bom->id,
        'inventory_item_id' => $ctx->rawMaterial->id,
        'quantity' => 1,
        'estimated_unit_cost' => 5,
    ], $lineOverrides));

    return ProductionOrder::create([
        'operating_unit_id' => $ctx->unit->id,
        'product_id' => $productRow->id,
        'bom_id' => $bom->id,
        'order_number' => 'PO-TEST',
        'quantity' => 1,
        'status' => ProductionOrderStatus::BomConfirmed,
    ]);
}

class ProductionOrderTestSetup
{
    public Company $company;

    public UnitBlueprint $blueprint;

    public OperatingUnit $unit;

    public User $owner;

    public Warehouse $warehouse;

    public InventoryItem $rawMaterial;

    public InventoryItem $foamBlockItem;
}

it('creates no material requests when all stock is on hand', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    StockLot::create([
        'inventory_item_id' => $ctx->rawMaterial->id,
        'warehouse_id' => $ctx->warehouse->id,
        'lot_number' => 'RAW-001',
        'quantity' => 100,
        'unit_cost' => 5,
        'status' => 'available',
    ]);

    $order = makeBomWithLine($ctx);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    expect(MaterialRequest::count())->toBe(0);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});

it('creates a cutter material request when no foam block with exact dims is on hand', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx, [
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'target_length_m' => 2.0,
        'target_width_m' => 1.5,
        'target_height_m' => 1.0,
    ]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $request = MaterialRequest::first();
    expect($request)->not->toBeNull();
    expect($request->fulfilling_module)->toBe(MaterialRequest::MODULE_CUTTER);
    expect((float) $request->quantity)->toBe(1.0);
    expect($request->target_dimensions['length_m'])->toBe('2.0000');

    expect($order->fresh()->awaiting_material_requests_count)->toBe(1);
});

it('creates a procurement material request when raw material is missing', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    // Order wants 10 units of raw material; no stock → procurement MR.
    $order = makeBomWithLine($ctx, ['quantity' => 10]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $request = MaterialRequest::first();
    expect($request)->not->toBeNull();
    expect($request->fulfilling_module)->toBe(MaterialRequest::MODULE_PROCUREMENT);
    expect((float) $request->quantity)->toBe(10.0);

    expect($order->fresh()->awaiting_material_requests_count)->toBe(1);
});

it('transition refuses in_production while awaiting_material_requests_count > 0', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx, [
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'target_length_m' => 2.0,
        'target_width_m' => 1.5,
        'target_height_m' => 1.0,
    ]);

    // Trigger resolution (creates a pending material request).
    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    // Operator tries to manually re-transition — still in_production → must refuse.
    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertStatus(422);
});

it('fulfilling a cutter material request via markFulfilled decrements the order counter', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx, [
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'target_length_m' => 2.0,
        'target_width_m' => 1.5,
        'target_height_m' => 1.0,
    ]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $request = MaterialRequest::first();
    expect($request)->not->toBeNull();

    $cutterOrder = CutterWorkOrder::create([
        'operating_unit_id' => $ctx->unit->id,
        'order_number' => 'CW-MR-TEST',
        'status' => CutterWorkOrderStatus::Completed,
    ]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/material-requests/{$request->id}/fulfill", [
            'fulfilled_by_type' => 'cutter_work_order',
            'fulfilled_by_id' => $cutterOrder->id,
        ])
        ->assertOk();

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_FULFILLED);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});

it('blocks matches on exact dimensions only', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    StockLot::create([
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'warehouse_id' => $ctx->warehouse->id,
        'lot_number' => 'FB-A',
        'quantity' => 1,
        'length_m' => 2.05,
        'width_m' => 1.5,
        'height_m' => 1.0,
        'unit_cost' => 100,
        'status' => 'available',
    ]);

    $order = makeBomWithLine($ctx, [
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'target_length_m' => 2.0,
        'target_width_m' => 1.5,
        'target_height_m' => 1.0,
    ]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    expect(MaterialRequest::where('status', 'pending')->count())->toBe(1);
});

it('allows exact dimension match to satisfy the line', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    // Foam block lots are serialized (quantity = 1 each).
    for ($i = 1; $i <= 5; $i++) {
        StockLot::create([
            'inventory_item_id' => $ctx->foamBlockItem->id,
            'warehouse_id' => $ctx->warehouse->id,
            'lot_number' => "FB-EXACT-{$i}",
            'quantity' => 1,
            'length_m' => 2.0,
            'width_m' => 1.5,
            'height_m' => 1.0,
            'unit_cost' => 100,
            'status' => 'available',
        ]);
    }

    $order = makeBomWithLine($ctx, [
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'target_length_m' => 2.0,
        'target_width_m' => 1.5,
        'target_height_m' => 1.0,
    ]);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    expect(MaterialRequest::count())->toBe(0);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});

it('lists material requests scoped to a production order', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->getJson("/api/v1/production-orders/{$order->id}/material-requests")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('cancelling a material request decrements the order counter', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/production-orders/{$order->id}/transition", [
            'status' => 'in_production',
        ])
        ->assertOk();

    $request = MaterialRequest::first();
    expect($request)->not->toBeNull();
    expect($order->fresh()->awaiting_material_requests_count)->toBe(1);

    $this->actingAs($ctx->owner)
        ->withHeader('X-Operating-Unit-ID', $ctx->unit->id)
        ->postJson("/api/v1/material-requests/{$request->id}/cancel")
        ->assertOk();

    expect($request->fresh()->status)->toBe(MaterialRequest::STATUS_CANCELLED);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});

it('production batch graded fulfills linked material request via markFulfilled', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx);

    $mr = MaterialRequest::create([
        'fulfilling_module' => MaterialRequest::MODULE_FOAM,
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'quantity' => 1,
        'status' => MaterialRequest::STATUS_PENDING,
        'requested_for_type' => 'production_order',
        'requested_for_id' => $order->id,
        'operating_unit_id' => $ctx->unit->id,
    ]);
    $order->update(['awaiting_material_requests_count' => 1]);

    $batch = ProductionBatch::create([
        'operating_unit_id' => $ctx->unit->id,
        'operation_number' => 1,
        'bun_width_m' => 2.4,
        'formula_params' => [],
        'status' => ProductionBatchStatus::Closed,
    ]);

    app(MaterialResolutionService::class)
        ->markFulfilled($mr, $batch);

    expect($mr->fresh()->status)->toBe(MaterialRequest::STATUS_FULFILLED);
    expect($mr->fresh()->fulfilled_by_type)->toBe('production_batch');
    expect($mr->fresh()->fulfilled_by_id)->toBe($batch->id);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});

it('cutter order completed fulfills linked material request via markFulfilled', function () {
    $ctx = new ProductionOrderTestSetup;
    foreach (get_object_vars($this) as $k => $v) {
        $ctx->$k = $v;
    }

    $order = makeBomWithLine($ctx);

    $mr = MaterialRequest::create([
        'fulfilling_module' => MaterialRequest::MODULE_CUTTER,
        'inventory_item_id' => $ctx->foamBlockItem->id,
        'quantity' => 1,
        'status' => MaterialRequest::STATUS_PENDING,
        'requested_for_type' => 'production_order',
        'requested_for_id' => $order->id,
        'operating_unit_id' => $ctx->unit->id,
    ]);
    $order->update(['awaiting_material_requests_count' => 1]);

    $cutterOrder = CutterWorkOrder::create([
        'operating_unit_id' => $ctx->unit->id,
        'order_number' => 'CW-FOR-MR',
        'status' => CutterWorkOrderStatus::InProduction,
    ]);

    app(MaterialResolutionService::class)
        ->markFulfilled($mr, $cutterOrder);

    expect($mr->fresh()->status)->toBe(MaterialRequest::STATUS_FULFILLED);
    expect($mr->fresh()->fulfilled_by_type)->toBe('cutter_work_order');
    expect($mr->fresh()->fulfilled_by_id)->toBe($cutterOrder->id);
    expect($order->fresh()->awaiting_material_requests_count)->toBe(0);
});
