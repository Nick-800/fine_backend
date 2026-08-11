<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockAdjustmentRequest;
use App\Models\StockLot;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Support\CurrentUnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Two units, a user scoped to each, and one company-wide Owner.
 * Every record below is created with no unit context active, so nothing is
 * implicitly scoped during setup.
 */
beforeEach(function () {
    app(CurrentUnitContext::class)->clear();

    $this->company = Company::create(['name' => 'Fine Foam Mfg']);
    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $makeUnit = fn (string $name, string $code) => OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => $name,
        'code' => $code,
        'unit_type' => 'manufactory',
    ]);

    $this->unitA = $makeUnit('Foam Plant A', 'FOAM-A');
    $this->unitB = $makeUnit('Foam Plant B', 'FOAM-B');

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'WH A', 'code' => 'WH-A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'WH B', 'code' => 'WH-B']);

    $role = Role::create(['name' => 'Unit Manager', 'slug' => 'unit_manager']);

    $this->userA = User::factory()->create(['must_change_password' => false]);
    UserRole::create(['user_id' => $this->userA->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unitA->id]);

    // Company-wide role: no unit of its own, so nothing should be filtered for them.
    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->item = InventoryItem::create([
        'name' => 'Foam Block', 'sku' => 'BLOCK-1', 'item_type' => 'foam_block', 'unit_of_measure' => 'm3',
    ]);

    // One record of each kind in unit B — the unit userA must never reach.
    $this->batchB = ProductionBatch::create([
        'operating_unit_id' => $this->unitB->id,
        'operation_number' => 500,
        'bun_width_m' => 2.4,
        'status' => 'graded',
    ]);

    $this->categoryB = ItemCategory::create([
        'operating_unit_id' => $this->unitB->id, 'name' => 'B Category', 'code' => 'CAT-B',
    ]);

    $this->lotB = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-B-1',
        'quantity' => 5,
        'unit_cost' => 10,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    $this->adjustmentB = StockAdjustmentRequest::create([
        'operating_unit_id' => $this->unitB->id,
        'stock_lot_id' => $this->lotB->id,
        'reason_code' => 'damage',
        'quantity_delta' => -1,
        'status' => 'pending',
        'requested_by_user_id' => $this->userA->id,
    ]);

    app(CurrentUnitContext::class)->clear();

    $this->asA = fn () => $this->actingAs($this->userA)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id]);
});

test('a unit-scoped user cannot read another unit production batch', function () {
    ($this->asA)()->getJson("/api/v1/production-batches/{$this->batchB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit production batch', function () {
    ($this->asA)()->putJson("/api/v1/production-batches/{$this->batchB->id}", [
        'status' => 'closed',
        'record_version' => $this->batchB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->batchB->fresh()->status->value)->toBe('graded');
});

test('a unit-scoped user cannot delete another unit production batch', function () {
    ($this->asA)()->deleteJson("/api/v1/production-batches/{$this->batchB->id}")->assertNotFound();
});

test('a unit-scoped user cannot read another unit item category', function () {
    ($this->asA)()->getJson("/api/v1/item-categories/{$this->categoryB->id}")->assertNotFound();
});

test('a unit-scoped user cannot read another unit stock lot', function () {
    // Stock lots carry no operating_unit_id; they are scoped through their warehouse.
    ($this->asA)()->getJson("/api/v1/stock-lots/{$this->lotB->id}")->assertNotFound();
});

test('a unit-scoped user cannot approve another unit stock adjustment', function () {
    ($this->asA)()->postJson("/api/v1/stock-adjustment-requests/{$this->adjustmentB->id}/approve")
        ->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->adjustmentB->fresh()->status)->toBe('pending');
});

test('listings exclude other units', function () {
    ($this->asA)()->getJson('/api/v1/production-batches')->assertStatus(200)->assertJsonPath('total', 0);
    ($this->asA)()->getJson('/api/v1/stock-lots')->assertStatus(200)->assertJsonPath('total', 0);
    // item-categories returns a plain collection rather than a paginator
    ($this->asA)()->getJson('/api/v1/item-categories')->assertStatus(200)->assertJsonCount(0);
});

test('a shared category with no unit stays visible to every unit', function () {
    app(CurrentUnitContext::class)->clear();

    ItemCategory::create(['operating_unit_id' => null, 'name' => 'Shared', 'code' => 'CAT-SHARED']);

    // Visible to a unit-scoped user despite belonging to no unit...
    ($this->asA)()->getJson('/api/v1/item-categories')
        ->assertStatus(200)
        ->assertJsonCount(1)
        ->assertJsonFragment(['code' => 'CAT-SHARED']);

    // ...while unit B's own category remains hidden from unit A.
    ($this->asA)()->getJson('/api/v1/item-categories')->assertJsonMissing(['code' => 'CAT-B']);
});

test('a company-wide owner still sees across every unit', function () {
    // The whole point of the scope being conditional: Owner must not be filtered.
    $this->actingAs($this->owner)->getJson('/api/v1/production-batches')
        ->assertStatus(200)->assertJsonPath('total', 1);

    $this->actingAs($this->owner)->getJson("/api/v1/production-batches/{$this->batchB->id}")
        ->assertStatus(200)->assertJsonPath('operation_number', 500);

    $this->actingAs($this->owner)->getJson("/api/v1/stock-lots/{$this->lotB->id}")
        ->assertStatus(200)->assertJsonPath('lot_number', 'LOT-B-1');
});

test('an owner scoped to a unit by header is filtered to it', function () {
    $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id])
        ->getJson("/api/v1/production-batches/{$this->batchB->id}")
        ->assertNotFound();
});

test('a stock adjustment is filed against the caller unit, not the body', function () {
    $lotA = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-A-1',
        'quantity' => 10,
        'unit_cost' => 10,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    ($this->asA)()->postJson('/api/v1/stock-adjustment-requests', [
        'operating_unit_id' => $this->unitB->id, // must be ignored
        'stock_lot_id' => $lotA->id,
        'reason_code' => 'damage',
        'quantity_delta' => -1,
    ])->assertStatus(201)->assertJsonPath('operating_unit_id', $this->unitA->id);
});

test('a tank refill is applied to the caller unit, not the body', function () {
    $chemical = InventoryItem::create([
        'name' => 'TDI', 'sku' => 'CHEM-TDI', 'item_type' => 'raw_material', 'unit_of_measure' => 'liter',
    ]);

    ($this->asA)()->postJson('/api/v1/tank-stocks/refill', [
        'chemical_inventory_item_id' => $chemical->id,
        'operating_unit_id' => $this->unitB->id, // must be ignored
        'refill_quantity' => 100,
        'refill_unit_cost' => 5,
    ])->assertStatus(201)->assertJsonPath('operating_unit_id', $this->unitA->id);
});

test('operation numbers stay globally unique across units', function () {
    // The expected-number query must ignore unit scoping, or two units would both
    // be told "next is 1" and collide on the unique index.
    ($this->asA)()->postJson('/api/v1/production-batches', [
        'operation_number' => 500, // already used by unit B
        'bun_width_m' => 2.4,
        'confirm_non_sequential' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('operation_number');
});
