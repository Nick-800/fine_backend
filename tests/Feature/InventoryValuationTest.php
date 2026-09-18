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
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Blueprint', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unitA = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Furniture', 'code' => 'FURN-01', 'unit_type' => 'manufactory',
    ]);
    $this->unitB = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $this->blueprint->id,
        'name' => 'Showroom', 'code' => 'STORE-01', 'unit_type' => 'store',
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->warehouseA = Warehouse::create(['operating_unit_id' => $this->unitA->id, 'name' => 'WH-A', 'code' => 'WH-A']);
    $this->warehouseB = Warehouse::create(['operating_unit_id' => $this->unitB->id, 'name' => 'WH-B', 'code' => 'WH-B']);

    $this->item = InventoryItem::create([
        'name' => 'Foam Block', 'sku' => 'BLOCK-TEST', 'item_type' => 'foam_block', 'unit_of_measure' => 'each',
    ]);

    // Lots in two units; one foam_block lot per warehouse.
    StockLot::create([
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouseA->id,
        'lot_number' => 'LOT-A-1', 'quantity' => 1, 'unit_cost' => 1000,
        'status' => 'available',
        'length_m' => 2.0, 'width_m' => 1.0, 'height_m' => 0.5,
    ]);
    StockLot::create([
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouseB->id,
        'lot_number' => 'LOT-B-1', 'quantity' => 1, 'unit_cost' => 2000,
        'status' => 'available',
        'length_m' => 2.0, 'width_m' => 1.0, 'height_m' => 0.5,
    ]);
});

test('a company-wide caller without a unit header gets the company rollup', function () {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/inventory/valuation')
        ->assertOk()
        ->json();

    // Total = 1000 (unit A) + 2000 (unit B) = 3000 LYD
    expect((float) $response['company_total_valuation'])->toBe(3000.0);
});

test('a company-wide caller with operating_unit_id query gets just that unit', function () {
    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/inventory/valuation?operating_unit_id={$this->unitA->id}")
        ->assertOk()
        ->json();

    expect((float) $response['total_valuation'])->toBe(1000.0)
        ->and($response['operating_unit_id'])->toBe($this->unitA->id);
});

test('a company-wide caller with the X-Operating-Unit-ID header gets just that unit', function () {
    $response = $this->actingAs($this->owner)
        ->withHeaders(['X-Operating-Unit-ID' => $this->unitB->id])
        ->getJson('/api/v1/inventory/valuation')
        ->assertOk()
        ->json();

    expect((float) $response['total_valuation'])->toBe(2000.0)
        ->and($response['operating_unit_id'])->toBe($this->unitB->id);
});
