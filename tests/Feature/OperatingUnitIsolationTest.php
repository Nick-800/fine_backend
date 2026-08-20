<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Entity;
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
use Database\Seeders\ChartOfAccountsSeeder;
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
    $this->seed(ChartOfAccountsSeeder::class);
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

    // Bulk material: these lots exist to test scoping, and carry quantities > 1,
    // which INV-02 would reject on a serialized foam block.
    $this->item = InventoryItem::create([
        'name' => 'Bulk Filler', 'sku' => 'FILL-1', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    ]);

    $this->blockItem = InventoryItem::create([
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

    $entityEmpB = Entity::create(['name' => 'Worker Unit B', 'entity_type' => 'individual', 'is_active' => true]);
    $this->employeeB = Employee::create([
        'entity_id' => $entityEmpB->id,
        'operating_unit_id' => $this->unitB->id,
        'job_title' => 'Cutter Operator',
        'labor_role' => 'operator',
        'pay_type' => 'hourly',
        'hourly_rate' => 15.00,
        'hire_date' => '2026-02-01',
        'status' => 'active',
    ]);

    $entityClientB = Entity::create(['name' => 'Client Unit B', 'entity_type' => 'organization', 'is_active' => true]);
    $this->clientB = Client::create([
        'entity_id' => $entityClientB->id,
        'operating_unit_id' => $this->unitB->id,
        'credit_limit' => 30000.00,
        'current_balance' => 1200.00,
        'payment_terms_days' => 30,
        'status' => 'active',
    ]);

    app(CurrentUnitContext::class)->clear();

    $this->asA = fn () => $this->actingAs($this->userA)->withHeaders(['X-Operating-Unit-ID' => $this->unitA->id]);
});

test('a unit id from another database is rejected with a recoverable code', function () {
    // A client holding a unit id from a reseeded database would otherwise retry
    // the same bad header forever. The code lets it drop the stale value.
    $this->actingAs($this->userA)
        ->withHeaders(['X-Operating-Unit-ID' => '019ff000-0000-7000-8000-00000000dead'])
        ->getJson('/api/v1/production-batches')
        ->assertStatus(400)
        ->assertJsonPath('code', 'INVALID_OPERATING_UNIT');
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

test('a stock lot cannot be created into another unit warehouse', function () {
    // exists: issues a raw query and ignores global scopes, so this needs the
    // scope-aware rule to be rejected at all.
    ($this->asA)()->postJson('/api/v1/stock-lots', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-CROSS-1',
        'quantity' => 1,
        'unit_cost' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    app(CurrentUnitContext::class)->clear();
    expect(StockLot::where('lot_number', 'LOT-CROSS-1')->exists())->toBeFalse();
});

test('a stock lot cannot be moved into another unit warehouse', function () {
    $lotA = StockLot::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-A-MOVE',
        'quantity' => 1,
        'unit_cost' => 10,
        'grade' => 'standard',
        'status' => 'available',
    ]);

    ($this->asA)()->putJson("/api/v1/stock-lots/{$lotA->id}", [
        'warehouse_id' => $this->warehouseB->id,
        'record_version' => $lotA->record_version,
    ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
});

test('blocks cannot be registered into another unit warehouse', function () {
    $batchA = ProductionBatch::create([
        'operating_unit_id' => $this->unitA->id,
        'operation_number' => 600,
        'bun_width_m' => 2.4,
        'status' => 'graded',
    ]);

    ($this->asA)()->postJson("/api/v1/production-batches/{$batchA->id}/blocks", [
        'groups' => [[
            'kind' => 'block', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
            'inventory_item_id' => $this->blockItem->id,
            'warehouse_id' => $this->warehouseB->id,
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('groups.0.warehouse_id');
});

test('an adjustment cannot be filed against another unit stock lot', function () {
    ($this->asA)()->postJson('/api/v1/stock-adjustment-requests', [
        'stock_lot_id' => $this->lotB->id,
        'reason_code' => 'damage',
        'quantity_delta' => -1,
    ])->assertStatus(422)->assertJsonValidationErrors('stock_lot_id');
});

test('a stock lot cannot be attached to another unit production batch', function () {
    ($this->asA)()->postJson('/api/v1/stock-lots', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-CROSS-2',
        'quantity' => 1,
        'unit_cost' => 10,
        'production_batch_id' => $this->batchB->id,
    ])->assertStatus(422)->assertJsonValidationErrors('production_batch_id');
});

test('an inventory item cannot be filed under another unit category', function () {
    ($this->asA)()->postJson('/api/v1/inventory-items', [
        'name' => 'Cross Item',
        'sku' => 'CROSS-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'category_id' => $this->categoryB->id,
    ])->assertStatus(422)->assertJsonValidationErrors('category_id');
});

test('an inventory item can use a shared category', function () {
    app(CurrentUnitContext::class)->clear();
    $shared = ItemCategory::create(['operating_unit_id' => null, 'name' => 'Shared', 'code' => 'CAT-SH2']);

    ($this->asA)()->postJson('/api/v1/inventory-items', [
        'name' => 'Shared Item',
        'sku' => 'SHARED-1',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
        'category_id' => $shared->id,
    ])->assertStatus(201);
});

test('an owner is not blocked by the scope-aware existence rule', function () {
    // With no unit in context the scopes no-op, so any real warehouse is valid.
    $this->actingAs($this->owner)->postJson('/api/v1/stock-lots', [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-OWNER-1',
        'quantity' => 1,
        'unit_cost' => 10,
    ])->assertStatus(201);
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

test('a unit-scoped user cannot read another unit employee', function () {
    ($this->asA)()->getJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit employee', function () {
    ($this->asA)()->putJson("/api/v1/employees/{$this->employeeB->id}", [
        'job_title' => 'Senior Cutter Operator',
        'record_version' => $this->employeeB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->employeeB->fresh()->job_title)->toBe('Cutter Operator');
});

test('a unit-scoped user cannot delete another unit employee', function () {
    ($this->asA)()->deleteJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect($this->employeeB->fresh()->trashed())->toBeFalse();
});

test('a unit-scoped user cannot read another unit client', function () {
    ($this->asA)()->getJson("/api/v1/clients/{$this->clientB->id}")->assertNotFound();
});

test('a unit-scoped user cannot modify another unit client', function () {
    ($this->asA)()->putJson("/api/v1/clients/{$this->clientB->id}", [
        'credit_limit' => 99999.00,
        'record_version' => $this->clientB->record_version,
    ])->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect((float) $this->clientB->fresh()->credit_limit)->toBe(30000.0);
});

test('a unit-scoped user cannot delete another unit client', function () {
    ($this->asA)()->deleteJson("/api/v1/clients/{$this->clientB->id}")->assertNotFound();

    app(CurrentUnitContext::class)->clear();
    expect(Client::withoutGlobalScopes()->whereKey($this->clientB->id)->exists())->toBeTrue();
});

test('employee and client listings exclude other units', function () {
    ($this->asA)()->getJson('/api/v1/employees')->assertStatus(200)->assertJsonCount(0, 'data');
    ($this->asA)()->getJson('/api/v1/clients')->assertStatus(200)->assertJsonCount(0, 'data');
});

test('a company-wide owner can read employees and clients across all units', function () {
    $this->actingAs($this->owner)->getJson("/api/v1/employees/{$this->employeeB->id}")
        ->assertStatus(200)->assertJsonPath('data.id', $this->employeeB->id);

    $this->actingAs($this->owner)->getJson("/api/v1/clients/{$this->clientB->id}")
        ->assertStatus(200)->assertJsonPath('data.id', $this->clientB->id);
});
