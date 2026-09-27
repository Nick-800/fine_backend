<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
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
        'name' => 'Foam Blueprint',
        'workflow_set' => [],
        'default_role_template' => [],
        'default_inventory_config' => [],
    ]);

    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Foam Factory',
        'code' => 'FOAM-01',
        'unit_type' => 'foam_manufactory',
    ]);

    $this->warehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Main Warehouse',
    ]);

    $this->otherWarehouse = Warehouse::create([
        'operating_unit_id' => $this->unit->id,
        'name' => 'Overflow Warehouse',
    ]);

    $this->user = User::factory()->create(['must_change_password' => false]);
    $this->role = Role::create(['name' => 'Foam Manager', 'slug' => 'foam-manager']);

    UserRole::create([
        'user_id' => $this->user->id,
        'role_id' => $this->role->id,
        'operating_unit_id' => $this->unit->id,
    ]);

    $this->itemA = InventoryItem::create([
        'name' => 'Foam Sheet Standard',
        'code' => 'SHEET-STD',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'm3',
    ]);

    $this->itemB = InventoryItem::create([
        'name' => 'Cotton Filler',
        'code' => 'FILL-COT',
        'item_type' => 'raw_material',
        'unit_of_measure' => 'kg',
    ]);
});

test('warehouse stock summary groups lots by item with weighted average cost', function () {
    // Two available lots of item A: 10@2.00 + 30@3.00 -> avg = (20+90)/40 = 2.75
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 10,
        'unit_cost' => 2.00,
        'status' => 'available',
    ]);
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 30,
        'unit_cost' => 3.00,
        'status' => 'available',
    ]);

    // One available lot of item B.
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemB->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 5,
        'unit_cost' => 10.00,
        'status' => 'available',
    ]);

    // A consumed lot of item A must be excluded from the summary entirely.
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 0,
        'unit_cost' => 9.00,
        'status' => 'consumed',
    ]);

    // A lot in a different warehouse must not leak into this warehouse's summary.
    StockLot::factory()->create([
        'inventory_item_id' => $this->itemA->id,
        'warehouse_id' => $this->otherWarehouse->id,
        'quantity' => 100,
        'unit_cost' => 1.00,
        'status' => 'available',
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson("/api/v1/warehouses/{$this->warehouse->id}/stock-summary");

    $response->assertOk();

    $rows = collect($response->json('rows'))->keyBy('inventory_item_id');

    expect($rows)->toHaveCount(2);

    $rowA = $rows[$this->itemA->id];
    expect((float) $rowA['total_quantity'])->toBe(40.0);
    expect((float) $rowA['avg_unit_cost'])->toBe(2.75);
    expect((float) $rowA['total_value'])->toBe(110.0);
    expect($rowA['lots_count'])->toBe(2);

    $rowB = $rows[$this->itemB->id];
    expect((float) $rowB['total_quantity'])->toBe(5.0);
    expect((float) $rowB['avg_unit_cost'])->toBe(10.0);

    expect((float) $response->json('total_value'))->toBe(160.0);
    expect($response->json('warehouse.id'))->toBe($this->warehouse->id);
});

test('warehouse stock summary is empty for a warehouse with no available stock', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson("/api/v1/warehouses/{$this->otherWarehouse->id}/stock-summary");

    $response->assertOk();
    expect($response->json('rows'))->toBe([]);
    expect((float) $response->json('total_value'))->toBe(0.0);
});
